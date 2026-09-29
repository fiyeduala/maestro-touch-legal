<?php

namespace App\Domain\Engagement;

use App\Domain\Clients\ClientContacts;
use App\Domain\Identity\Role;
use App\Domain\Intake\Enquiries;
use App\Domain\Intake\EnquiryStatus;
use App\Domain\Operations\Audit;
use App\Domain\RuleViolation;
use App\Models\Acceptance;
use App\Models\Client;
use App\Models\Enquiry;
use App\Models\Matter;
use App\Models\Quotation;
use App\Models\QuotationVersion;
use App\Models\User;
use App\Support\CanonicalJson;
use App\Support\Money;
use App\Support\References;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Quotations (spec §6, §10): one currency each, integer minor units, versioned. A version is frozen
 * when sent; later edits create a new version and the earlier one can no longer be accepted.
 * Accepting a quotation does not start representation and records no payment.
 */
class Quotations
{
    public const ACCEPT_STATEMENT = 'I accept this quotation (version :version, total :total) for the scope described. I understand this is not a payment and does not by itself start legal representation.';

    public const DECLINE_STATEMENT = 'I decline this quotation (version :version).';

    public function __construct(private ClientContacts $contacts) {}

    /**
     * @param  array{title: string, currency: string, scope: string, exclusions?: ?string, lines: list<array>, payment_stages?: ?list<array>, valid_until?: ?string, notes?: ?string}  $data
     */
    public function create(Client $client, ?Enquiry $enquiry, ?Matter $matter, array $data, User $actor): Quotation
    {
        Gate::forUser($actor)->authorize('create', Quotation::class);
        $this->assertContext($client, $enquiry, $matter, $actor);
        $currency = Money::assertCurrency($data['currency']);
        $content = $this->normalise($data);

        return DB::transaction(function () use ($client, $enquiry, $matter, $data, $currency, $content, $actor) {
            $quotation = Quotation::create([
                'reference' => References::next('quotations', 'QUO'),
                'client_id' => $client->id,
                'enquiry_id' => $enquiry?->id,
                'matter_id' => $matter?->id,
                'currency' => $currency,
                'title' => trim($data['title']),
                'status' => OfferStatus::Draft,
                'created_by' => $actor->id,
            ]);
            $version = $quotation->versions()->create([...$content, 'version' => 1, 'created_by' => $actor->id]);
            $quotation->forceFill(['current_version_id' => $version->id])->save();

            Audit::record('quotation.created', "Quotation {$quotation->reference} drafted ({$currency} ".Money::toDecimal($version->total_minor).')', $quotation, actor: $actor);

            return $quotation;
        });
    }

    /** Edits the draft version, or starts a new version if the current one was already sent. */
    public function revise(Quotation $quotation, array $data, User $actor): QuotationVersion
    {
        Gate::forUser($actor)->authorize('update', $quotation);
        $content = $this->normalise($data);

        return DB::transaction(function () use ($quotation, $data, $content, $actor) {
            $quotation = Quotation::lockForUpdate()->findOrFail($quotation->id);
            $current = $quotation->currentVersion;
            $quotation->forceFill(['title' => trim($data['title'] ?? $quotation->title)]);

            if ($current && ! $current->isFrozen()) {
                $current->update($content);
                $version = $current;
            } else {
                $version = $quotation->versions()->create([
                    ...$content,
                    'version' => ($quotation->versions()->max('version') ?? 0) + 1,
                    'created_by' => $actor->id,
                ]);
                $quotation->forceFill(['current_version_id' => $version->id, 'status' => OfferStatus::Draft]);
            }
            $quotation->save();
            Audit::record('quotation.revised', "Quotation {$quotation->reference} version {$version->version} edited", $quotation, actor: $actor);

            return $version;
        });
    }

    public function send(Quotation $quotation, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $quotation);
        if ($quotation->enquiry && ! $quotation->enquiry->isConflictCleared()) {
            throw new RuleViolation('The enquiry\'s conflict check must be cleared before a quotation is sent.');
        }

        DB::transaction(function () use ($quotation, $actor) {
            $quotation = Quotation::lockForUpdate()->findOrFail($quotation->id);
            $version = $quotation->currentVersion;
            if ($quotation->status !== OfferStatus::Draft || ! $version || $version->isFrozen()) {
                throw new RuleViolation('Only a draft quotation can be sent.');
            }
            if ($version->total_minor <= 0 || $version->lines === []) {
                throw new RuleViolation('Add at least one priced line before sending.');
            }
            if ($version->isExpired()) {
                throw new RuleViolation('The validity date has already passed.');
            }

            $version->forceFill([
                'sent_at' => now(),
                'sent_by' => $actor->id,
                'content_hash' => self::hash($version, $quotation->currency),
            ])->save();
            $quotation->forceFill(['status' => OfferStatus::Sent])->save();

            if ($quotation->enquiry) {
                app(Enquiries::class)->advance($quotation->enquiry, EnquiryStatus::QuoteSent, $actor, "Quotation {$quotation->reference} v{$version->version} sent");
            }
            Audit::record('quotation.sent', "Quotation {$quotation->reference} version {$version->version} sent to client", $quotation,
                ['after' => ['version' => $version->version, 'total_minor' => $version->total_minor, 'currency' => $quotation->currency]], actor: $actor);
        });

        $this->contacts->notify($quotation->client, 'A quotation is ready for you', 'Maestro Touch Legal has sent you a quotation to review.', '/portal/quotations/'.$quotation->id);
    }

    public function withdraw(Quotation $quotation, string $reason, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $quotation);
        if (mb_strlen(trim($reason)) < 5) {
            throw new RuleViolation('Record why the quotation is withdrawn.');
        }
        $quotation->forceFill(['status' => OfferStatus::Withdrawn])->save();
        Audit::record('quotation.withdrawn', "Quotation {$quotation->reference} withdrawn: ".trim($reason), $quotation, actor: $actor);
    }

    /**
     * The client's own decision on the exact version they viewed. Staff and administrators cannot do this.
     */
    public function respond(Quotation $quotation, int $versionId, string $decision, User $clientUser, ?string $comment, ?string $ip, ?string $userAgent): Acceptance
    {
        Gate::forUser($clientUser)->authorize('respondAsClient', $quotation);
        if (! in_array($decision, ['accepted', 'declined'], true)) {
            throw new RuleViolation('Choose to accept or decline.');
        }

        $acceptance = DB::transaction(function () use ($quotation, $versionId, $decision, $clientUser, $comment, $ip, $userAgent) {
            $quotation = Quotation::lockForUpdate()->findOrFail($quotation->id);
            $version = $quotation->currentVersion;
            if ($quotation->status !== OfferStatus::Sent || ! $version || $version->id !== $versionId || ! $version->isFrozen()) {
                throw new RuleViolation('This quotation has changed since you opened it. Please review the latest version.');
            }
            if ($decision === 'accepted' && $version->isExpired()) {
                throw new RuleViolation('This quotation has expired. Please ask the firm for an updated quotation.');
            }
            if (! hash_equals((string) $version->content_hash, self::hash($version, $quotation->currency))) {
                throw new RuleViolation('This quotation could not be verified. Please contact the firm.');
            }

            $statement = strtr($decision === 'accepted' ? self::ACCEPT_STATEMENT : self::DECLINE_STATEMENT, [
                ':version' => $version->version,
                ':total' => Money::format($version->total_minor, $quotation->currency),
            ]);
            $acceptance = Acceptance::create([
                'acceptable_type' => $version->getMorphClass(),
                'acceptable_id' => $version->id,
                'decision' => $decision,
                'method' => 'portal',
                'client_id' => $quotation->client_id,
                'user_id' => $clientUser->id,
                'signed_name' => $clientUser->name,
                'content_hash' => $version->content_hash,
                'statement' => $statement,
                'comment' => $comment ? trim($comment) : null,
                'ip' => $ip,
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
                'created_at' => now(),
            ]);
            $quotation->forceFill([
                'status' => $decision === 'accepted' ? OfferStatus::Accepted : OfferStatus::Declined,
                'accepted_version_id' => $decision === 'accepted' ? $version->id : null,
            ])->save();

            Audit::record("quotation.client_{$decision}", "Client {$decision} quotation {$quotation->reference} v{$version->version}", $quotation,
                ['after' => ['version' => $version->version, 'content_hash' => $version->content_hash]], actor: $clientUser);

            return $acceptance;
        });

        $this->contacts->alertStaff($quotation->enquiry, $quotation->matter,
            "Client {$decision} quotation {$quotation->reference}", "The client has {$decision} the quotation.", '/admin/quotations/'.$quotation->id);

        return $acceptance;
    }

    public static function hash(QuotationVersion $version, string $currency): string
    {
        return CanonicalJson::hash($version->canonicalContent($currency));
    }

    /**
     * Converts form input (major-unit decimal strings) into stored minor-unit content with totals.
     *
     * @return array<string, mixed>
     */
    public function normalise(array $data): array
    {
        $lines = [];
        $fees = 0;
        $expenses = 0;
        foreach ($data['lines'] ?? [] as $line) {
            $quantity = (int) ($line['quantity'] ?? 1);
            if ($quantity < 1 || $quantity > 10000) {
                throw new RuleViolation('Each line needs a quantity between 1 and 10,000.');
            }
            $unit = Money::parse((string) ($line['unit'] ?? ''));
            $kind = ($line['kind'] ?? 'fee') === 'expense' ? 'expense' : 'fee';
            $amount = $unit * $quantity;
            if ($kind === 'fee') {
                $fees += $amount;
            } else {
                $expenses += $amount;
            }
            $lines[] = ['kind' => $kind, 'description' => trim((string) ($line['description'] ?? '')), 'quantity' => $quantity, 'unit_minor' => $unit, 'amount_minor' => $amount];
        }
        $total = $fees + $expenses;

        $stages = [];
        foreach ($data['payment_stages'] ?? [] as $stage) {
            $stages[] = ['label' => trim((string) ($stage['label'] ?? '')), 'amount_minor' => Money::parse((string) ($stage['amount'] ?? '')), 'due' => trim((string) ($stage['due'] ?? ''))];
        }
        if ($stages !== [] && array_sum(array_column($stages, 'amount_minor')) !== $total) {
            throw new RuleViolation('Payment stages must add up exactly to the quotation total ('.Money::toDecimal($total).').');
        }

        return [
            'scope' => trim((string) $data['scope']),
            'exclusions' => filled($data['exclusions'] ?? null) ? trim($data['exclusions']) : null,
            'lines' => $lines,
            'payment_stages' => $stages ?: null,
            'fees_minor' => $fees,
            'expenses_minor' => $expenses,
            'total_minor' => $total,
            'valid_until' => $data['valid_until'] ?? null,
            'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
        ];
    }

    /** Converts a stored version back into form input. */
    public static function toForm(QuotationVersion $version): array
    {
        return [
            'scope' => $version->scope,
            'exclusions' => $version->exclusions,
            'lines' => array_map(fn ($l) => ['kind' => $l['kind'], 'description' => $l['description'], 'quantity' => $l['quantity'], 'unit' => Money::input($l['unit_minor'])], $version->lines ?? []),
            'payment_stages' => array_map(fn ($s) => ['label' => $s['label'], 'amount' => Money::input($s['amount_minor']), 'due' => $s['due']], $version->payment_stages ?? []),
            'valid_until' => $version->valid_until?->toDateString(),
            'notes' => $version->notes,
        ];
    }

    private function assertContext(Client $client, ?Enquiry $enquiry, ?Matter $matter, User $actor): void
    {
        if ($enquiry && $enquiry->client_id !== $client->id) {
            throw new RuleViolation('Link the enquiry to this client before preparing a quotation.');
        }
        if ($matter && $matter->client_id !== $client->id) {
            throw new RuleViolation('The matter belongs to a different client.');
        }
        if ($enquiry && ! Gate::forUser($actor)->allows('update', $enquiry) && ! $actor->hasRole(Role::FinanceOfficer)) {
            throw new RuleViolation('You do not have access to this enquiry.');
        }
        if ($matter && ! Gate::forUser($actor)->allows('view', $matter) && ! $actor->hasRole(Role::FinanceOfficer)) {
            throw new RuleViolation('You do not have access to this matter.');
        }
        if (! $enquiry && ! $matter && ! $actor->isFullAdministrator() && ! $actor->hasRole(Role::FinanceOfficer)) {
            throw new RuleViolation('A quotation must belong to an enquiry you own or a matter you work on.');
        }
    }
}
