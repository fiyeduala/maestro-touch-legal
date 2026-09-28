<?php

namespace App\Domain\Engagement;

use App\Domain\Clients\ClientContacts;
use App\Domain\Documents\Documents;
use App\Domain\Intake\Enquiries;
use App\Domain\Intake\EnquiryStatus;
use App\Domain\Matters\Matters;
use App\Domain\Operations\Audit;
use App\Domain\Operations\Settings;
use App\Domain\Operations\StaffNotifier;
use App\Domain\RuleViolation;
use App\Models\Acceptance;
use App\Models\Engagement;
use App\Models\EngagementTemplate;
use App\Models\EngagementVersion;
use App\Models\Enquiry;
use App\Models\Matter;
use App\Models\QuotationVersion;
use App\Models\User;
use App\Notifications\StaffAlert;
use App\Support\CanonicalJson;
use App\Support\References;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Mews\Purifier\Facades\Purifier;

/**
 * Engagement terms (spec §6). Terms are versioned and frozen when sent. The client accepts one exact
 * version, or staff record a signed paper copy with evidence. Representation starts only when a full
 * administrator then approves the engagement internally, which opens the matter. Payment plays no part.
 */
class Engagements
{
    public const ACCEPT_STATEMENT = 'I, :name, accept the engagement terms ":title" (reference :reference, version :version) and agree that Maestro Touch Legal may act for me on this matter once the firm confirms it has opened the matter.';

    public const DECLINE_STATEMENT = 'I decline the engagement terms ":title" (reference :reference, version :version).';

    public const OFFLINE_STATEMENT = 'Signed paper copy of the engagement terms ":title" (reference :reference, version :version), signed by :name on :date, recorded by :recorder.';

    public function __construct(
        private ClientContacts $contacts,
        private Matters $matters,
        private Documents $documents,
        private Enquiries $enquiries,
    ) {}

    /** Engagements always come from an enquiry, so every matter has had a conflict check. */
    public function create(Enquiry $enquiry, EngagementTemplate $template, string $title, ?QuotationVersion $quotationVersion, User $actor): Engagement
    {
        Gate::forUser($actor)->authorize('create', Engagement::class);
        Gate::forUser($actor)->authorize('view', $enquiry);
        $client = $enquiry->client;
        if (! $client) {
            throw new RuleViolation('Link the enquiry to a client before preparing engagement terms.');
        }
        if (! $enquiry->status->isOpen()) {
            throw new RuleViolation('The enquiry is no longer open.');
        }
        if (! $template->is_active) {
            throw new RuleViolation('That template is not active.');
        }
        if ($quotationVersion && ($quotationVersion->quotation->client_id !== $client->id || $quotationVersion->quotation->accepted_version_id !== $quotationVersion->id)) {
            throw new RuleViolation('Only a quotation this client has accepted can be linked.');
        }
        $title = trim($title);
        if ($title === '') {
            throw new RuleViolation('Give the engagement a title.');
        }

        return DB::transaction(function () use ($enquiry, $client, $template, $title, $quotationVersion, $actor) {
            $engagement = Engagement::create([
                'reference' => References::next('engagements', 'ENG'),
                'client_id' => $client->id,
                'enquiry_id' => $enquiry->id,
                'quotation_version_id' => $quotationVersion?->id,
                'title' => $title,
                'status' => OfferStatus::Draft,
                'created_by' => $actor->id,
            ]);
            $body = self::fill($template->body, [
                '{client_name}' => $client->display_name,
                '{client_reference}' => $client->reference,
                '{service}' => $enquiry->service?->name ?? '',
                '{engagement_title}' => $title,
                '{firm_name}' => (string) Settings::get('site.title'),
                '{date}' => now()->timezone(config('app.firm_timezone'))->format('j F Y'),
            ]);
            $version = $engagement->versions()->create([
                'version' => 1,
                'template_id' => $template->id,
                'template_version' => $template->version,
                'body' => self::clean($body),
                'created_by' => $actor->id,
            ]);
            $engagement->forceFill(['current_version_id' => $version->id])->save();
            $this->enquiries->event($enquiry, 'engagement_drafted', null, null, "Engagement {$engagement->reference} drafted", $actor);
            Audit::record('engagement.created', "Engagement {$engagement->reference} drafted from template \"{$template->name}\" v{$template->version}", $engagement, actor: $actor);

            return $engagement;
        });
    }

    /** Edits the draft, or starts a new version if the current one was already sent. */
    public function revise(Engagement $engagement, string $title, string $body, User $actor): EngagementVersion
    {
        Gate::forUser($actor)->authorize('update', $engagement);
        $body = self::clean($body);
        if (trim(strip_tags($body)) === '') {
            throw new RuleViolation('The terms cannot be empty.');
        }

        return DB::transaction(function () use ($engagement, $title, $body, $actor) {
            $engagement = Engagement::lockForUpdate()->findOrFail($engagement->id);
            $current = $engagement->currentVersion;
            $engagement->title = trim($title) !== '' ? trim($title) : $engagement->title;

            if ($current && ! $current->isFrozen()) {
                $current->update(['body' => $body]);
                $version = $current;
            } else {
                $version = $engagement->versions()->create([
                    'version' => ($engagement->versions()->max('version') ?? 0) + 1,
                    'template_id' => $current?->template_id,
                    'template_version' => $current?->template_version,
                    'body' => $body,
                    'created_by' => $actor->id,
                ]);
                $engagement->forceFill(['current_version_id' => $version->id, 'status' => OfferStatus::Draft]);
            }
            $engagement->save();
            Audit::record('engagement.revised', "Engagement {$engagement->reference} version {$version->version} edited", $engagement, actor: $actor);

            return $version;
        });
    }

    public function send(Engagement $engagement, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $engagement);
        $this->assertConflictCleared($engagement);

        DB::transaction(function () use ($engagement, $actor) {
            $engagement = Engagement::lockForUpdate()->findOrFail($engagement->id);
            $version = $engagement->currentVersion;
            if ($engagement->status !== OfferStatus::Draft || ! $version || $version->isFrozen()) {
                throw new RuleViolation('Only draft terms can be sent.');
            }
            $version->forceFill(['sent_at' => now(), 'sent_by' => $actor->id, 'content_hash' => self::hash($engagement, $version)])->save();
            $engagement->forceFill(['status' => OfferStatus::Sent])->save();

            if ($engagement->enquiry) {
                $this->enquiries->advance($engagement->enquiry, EnquiryStatus::EngagementPending, $actor, "Engagement {$engagement->reference} v{$version->version} sent");
            }
            Audit::record('engagement.sent', "Engagement {$engagement->reference} version {$version->version} sent to client", $engagement,
                ['after' => ['version' => $version->version, 'content_hash' => $version->content_hash]], actor: $actor);
        });

        $this->contacts->notify($engagement->client, 'Engagement terms are ready for you', 'Maestro Touch Legal has sent you engagement terms to review.', '/portal/engagements/'.$engagement->id);
    }

    public function withdraw(Engagement $engagement, string $reason, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $engagement);
        if (mb_strlen(trim($reason)) < 5) {
            throw new RuleViolation('Record why the terms are withdrawn.');
        }
        $engagement->forceFill(['status' => OfferStatus::Withdrawn])->save();
        Audit::record('engagement.withdrawn', "Engagement {$engagement->reference} withdrawn: ".trim($reason), $engagement, actor: $actor);
    }

    /** The client's own decision, with a typed signature, on the exact version they viewed. */
    public function respond(Engagement $engagement, int $versionId, string $decision, ?string $signedName, ?string $comment, User $clientUser, ?string $ip, ?string $userAgent): Acceptance
    {
        Gate::forUser($clientUser)->authorize('respondAsClient', $engagement);
        if (! in_array($decision, ['accepted', 'declined'], true)) {
            throw new RuleViolation('Choose to accept or decline.');
        }
        $signedName = trim((string) $signedName);
        if ($decision === 'accepted' && mb_strlen($signedName) < 3) {
            throw new RuleViolation('Type your full name to sign.');
        }

        $acceptance = DB::transaction(function () use ($engagement, $versionId, $decision, $signedName, $comment, $clientUser, $ip, $userAgent) {
            [$engagement, $version] = $this->lockSentVersion($engagement, $versionId, 'These terms have changed since you opened them. Please review the latest version.');
            $statement = strtr($decision === 'accepted' ? self::ACCEPT_STATEMENT : self::DECLINE_STATEMENT, [
                ':name' => $signedName,
                ':title' => $engagement->title,
                ':reference' => $engagement->reference,
                ':version' => $version->version,
            ]);
            $acceptance = Acceptance::create([
                'acceptable_type' => $version->getMorphClass(),
                'acceptable_id' => $version->id,
                'decision' => $decision,
                'method' => 'portal',
                'client_id' => $engagement->client_id,
                'user_id' => $clientUser->id,
                'signed_name' => $signedName !== '' ? $signedName : $clientUser->name,
                'content_hash' => $version->content_hash,
                'statement' => $statement,
                'comment' => filled($comment) ? trim($comment) : null,
                'ip' => $ip,
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
                'created_at' => now(),
            ]);
            $this->markDecided($engagement, $version, $decision, $clientUser, "Client {$decision} engagement {$engagement->reference} v{$version->version}");

            return $acceptance;
        });

        $this->alertDecision($engagement, $decision);

        return $acceptance;
    }

    /**
     * Staff record a paper copy the client signed and returned. The scan is kept as evidence; staff never
     * accept on the client's behalf through the portal.
     */
    public function recordOfflineAcceptance(Engagement $engagement, int $versionId, UploadedFile $evidence, string $signedName, string $signedOn, ?string $note, User $actor): Acceptance
    {
        Gate::forUser($actor)->authorize('recordOfflineAcceptance', $engagement);
        $signedName = trim($signedName);
        if (mb_strlen($signedName) < 3) {
            throw new RuleViolation('Enter the name of the person who signed.');
        }
        $signedDate = Carbon::parse($signedOn);
        if ($signedDate->isFuture()) {
            throw new RuleViolation('The signing date cannot be in the future.');
        }
        // Checked again under lock below; this avoids storing evidence for terms that have already changed.
        if ($engagement->current_version_id !== $versionId) {
            throw new RuleViolation('These terms have changed. Record the signature against the version the client signed.');
        }

        $document = $this->documents->storeEvidence($engagement->client_id, $engagement->enquiry_id, $evidence,
            "Signed engagement terms {$engagement->reference}", $actor);

        $acceptance = DB::transaction(function () use ($engagement, $versionId, $document, $signedName, $signedDate, $note, $actor) {
            [$engagement, $version] = $this->lockSentVersion($engagement, $versionId, 'These terms have changed. Record the signature against the version the client signed.');
            $acceptance = Acceptance::create([
                'acceptable_type' => $version->getMorphClass(),
                'acceptable_id' => $version->id,
                'decision' => 'accepted',
                'method' => 'offline_signed',
                'client_id' => $engagement->client_id,
                'recorded_by' => $actor->id,
                'signed_name' => $signedName,
                'content_hash' => $version->content_hash,
                'statement' => strtr(self::OFFLINE_STATEMENT, [
                    ':title' => $engagement->title,
                    ':reference' => $engagement->reference,
                    ':version' => $version->version,
                    ':name' => $signedName,
                    ':date' => $signedDate->format('j F Y'),
                    ':recorder' => $actor->name,
                ]),
                'comment' => filled($note) ? trim($note) : null,
                'evidence_document_id' => $document->id,
                'created_at' => now(),
            ]);
            $this->markDecided($engagement, $version, 'accepted', $actor, "Signed paper terms for {$engagement->reference} v{$version->version} recorded (signed by {$signedName})");

            return $acceptance;
        });

        $this->alertDecision($engagement, 'accepted');

        return $acceptance;
    }

    /**
     * Internal approval by a full administrator. Opens the matter, starts representation and converts the
     * enquiry. Nothing here depends on payment.
     */
    public function approve(Engagement $engagement, User $responsible, ?string $note, User $actor): Matter
    {
        Gate::forUser($actor)->authorize('approve', $engagement);
        $this->assertConflictCleared($engagement);

        $matter = DB::transaction(function () use ($engagement, $responsible, $note, $actor) {
            $engagement = Engagement::lockForUpdate()->findOrFail($engagement->id);
            if ($engagement->status !== OfferStatus::Accepted || $engagement->matter_id) {
                throw new RuleViolation('Only accepted terms that have not yet opened a matter can be approved.');
            }
            $version = $engagement->acceptedVersion;
            if (! $version || ! hash_equals((string) $version->content_hash, self::hash($engagement, $version))) {
                throw new RuleViolation('The accepted terms could not be verified. Do not approve; investigate first.');
            }

            $matter = $this->matters->open($engagement, $responsible, $actor);
            $engagement->forceFill([
                'status' => OfferStatus::Approved,
                'approved_at' => now(),
                'approved_by' => $actor->id,
                'approval_note' => filled($note) ? trim($note) : null,
                'matter_id' => $matter->id,
            ])->save();

            if ($enquiry = $engagement->enquiry) {
                $from = $enquiry->status;
                $enquiry->forceFill(['status' => EnquiryStatus::Converted, 'converted_at' => now(), 'matter_id' => $matter->id])->save();
                $this->enquiries->event($enquiry, 'converted', $from, EnquiryStatus::Converted, "Matter {$matter->reference} opened", $actor);
                // Carry the intake record into the matter: parties (for future conflict checks), quotations and files.
                $enquiry->parties()->whereNull('matter_id')->update(['matter_id' => $matter->id]);
                $enquiry->quotations()->whereNull('matter_id')->update(['matter_id' => $matter->id]);
                $enquiry->documents()->whereNull('matter_id')->update(['matter_id' => $matter->id]);
            }

            Audit::record('engagement.approved', "Engagement {$engagement->reference} approved; matter {$matter->reference} opened; representation started", $engagement,
                ['after' => ['matter_id' => $matter->id, 'accepted_version_id' => $version->id]], actor: $actor);

            return $matter;
        });

        $this->contacts->notify($engagement->client, "Your matter {$matter->reference} is open", 'Maestro Touch Legal has opened your matter.', "/portal/matters/{$matter->id}");
        if ($responsible->id !== $actor->id) {
            $responsible->notify(new StaffAlert("You are responsible for {$matter->reference}", "Matter {$matter->reference} has been opened with you as responsible lawyer.", "/admin/matters/{$matter->id}"));
        }

        return $matter;
    }

    public static function hash(Engagement $engagement, EngagementVersion $version): string
    {
        return CanonicalJson::hash([
            'reference' => $engagement->reference,
            'client_id' => $engagement->client_id,
            'title' => $engagement->title,
            'version' => $version->version,
            'body' => $version->body,
        ]);
    }

    /** Placeholder values are escaped; the template body itself is sanitised afterwards. */
    public static function fill(string $body, array $values): string
    {
        return strtr($body, array_map(fn ($v) => e((string) $v), $values));
    }

    public static function clean(string $body): string
    {
        return Purifier::clean($body, 'content');
    }

    /** @return array{0: Engagement, 1: EngagementVersion} */
    private function lockSentVersion(Engagement $engagement, int $versionId, string $changedMessage): array
    {
        $engagement = Engagement::lockForUpdate()->findOrFail($engagement->id);
        $version = $engagement->currentVersion;
        if ($engagement->status !== OfferStatus::Sent || ! $version || $version->id !== $versionId || ! $version->isFrozen()) {
            throw new RuleViolation($changedMessage);
        }
        if (! hash_equals((string) $version->content_hash, self::hash($engagement, $version))) {
            throw new RuleViolation('These terms could not be verified. Please contact the firm.');
        }

        return [$engagement, $version];
    }

    private function markDecided(Engagement $engagement, EngagementVersion $version, string $decision, User $actor, string $summary): void
    {
        $engagement->forceFill([
            'status' => $decision === 'accepted' ? OfferStatus::Accepted : OfferStatus::Declined,
            'accepted_version_id' => $decision === 'accepted' ? $version->id : null,
        ])->save();
        if ($engagement->enquiry) {
            $this->enquiries->event($engagement->enquiry, 'engagement_'.$decision, null, null, $summary, $actor);
        }
        Audit::record('engagement.'.$decision, $summary, $engagement, ['after' => ['version' => $version->version, 'content_hash' => $version->content_hash]], actor: $actor);
    }

    private function alertDecision(Engagement $engagement, string $decision): void
    {
        $path = '/admin/engagements/'.$engagement->id;
        $this->contacts->alertStaff($engagement->enquiry, null, "Engagement {$engagement->reference} {$decision}", "The engagement terms have been {$decision}.", $path);
        if ($decision === 'accepted') {
            // Approval is reserved to full administrators, so they are always told.
            StaffNotifier::administrators(new StaffAlert("Engagement {$engagement->reference} awaits approval",
                'Accepted engagement terms are waiting for internal approval before the matter opens.', $path), 'Engagement approval');
        }
    }

    private function assertConflictCleared(Engagement $engagement): void
    {
        if (! $engagement->enquiry || ! $engagement->enquiry->isConflictCleared()) {
            throw new RuleViolation('The enquiry\'s conflict check must be cleared first.');
        }
    }
}
