<?php

namespace App\Domain\Billing;

use App\Domain\Identity\Role;
use App\Domain\Operations\Audit;
use App\Domain\RuleViolation;
use App\Models\BillingRate;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Time recording (D53). Staff record their own minutes on a matter, by hand or with a timer. Finance
 * bills unbilled entries on a draft invoice: each becomes a fee line at the staff member's hourly rate
 * in the invoice currency (a rate for that matter first, then their default). Nothing is converted.
 */
class TimeEntries
{
    public const MAX_MINUTES = 1440;

    /** Who records time, and so who has rates. */
    public const RECORDING_ROLES = [Role::Lawyer, Role::CaseOfficer, Role::TechnicalAdministrator, Role::FirmPrincipal];

    /**
     * @param  array{worked_on: string, minutes: int, description: string, billable?: bool}  $data
     */
    public function record(Matter $matter, array $data, User $actor): TimeEntry
    {
        Gate::forUser($actor)->authorize('record', [TimeEntry::class, $matter]);
        $clean = $this->clean($data);

        return DB::transaction(function () use ($matter, $clean, $actor) {
            $entry = TimeEntry::create($clean + [
                'matter_id' => $matter->id,
                'client_id' => $matter->client_id,
                'user_id' => $actor->id,
                'created_by' => $actor->id,
            ]);
            Audit::record('time.recorded', "Time recorded on {$matter->reference}: ".TimeEntry::duration($entry->minutes), $entry, actor: $actor);

            return $entry;
        });
    }

    /** One running timer per person. The entry is finished (and gets its minutes) when it is stopped. */
    public function startTimer(Matter $matter, User $actor): TimeEntry
    {
        Gate::forUser($actor)->authorize('record', [TimeEntry::class, $matter]);

        if ($matter->isClosed()) {
            throw new RuleViolation('This matter is closed. Record the time by hand instead.');
        }

        return DB::transaction(function () use ($matter, $actor) {
            User::whereKey($actor->id)->lockForUpdate()->first();
            if ($running = TimeEntry::running()->where('user_id', $actor->id)->with('matter')->first()) {
                throw new RuleViolation("Your timer is already running on {$running->matter->reference}. Stop it first.");
            }
            $entry = TimeEntry::create([
                'matter_id' => $matter->id,
                'client_id' => $matter->client_id,
                'user_id' => $actor->id,
                'worked_on' => $this->today(),
                'started_at' => now(),
                'created_by' => $actor->id,
            ]);
            Audit::record('time.timer_started', "Timer started on {$matter->reference}", $entry, actor: $actor);

            return $entry;
        });
    }

    /** Rounds up to the whole minute. A timer left running for over a day must be discarded and the time entered by hand. */
    public function stopTimer(TimeEntry $entry, string $description, bool $billable, User $actor): TimeEntry
    {
        Gate::forUser($actor)->authorize('stop', $entry);

        return DB::transaction(function () use ($entry, $description, $billable, $actor) {
            $entry = TimeEntry::lockForUpdate()->findOrFail($entry->id);
            if (! $entry->isRunning()) {
                throw new RuleViolation('This timer has already been stopped.');
            }
            $minutes = max(1, (int) ceil($entry->started_at->diffInSeconds(now()) / 60));
            if ($minutes > self::MAX_MINUTES) {
                throw new RuleViolation('This timer ran for more than 24 hours. Discard it and record the time by hand.');
            }
            $description = $this->description($description);
            $entry->forceFill(['minutes' => $minutes, 'started_at' => null, 'description' => $description, 'billable' => $billable])->save();
            Audit::record('time.recorded', "Time recorded on {$entry->matter->reference} with a timer: ".TimeEntry::duration($minutes), $entry, actor: $actor);

            return $entry;
        });
    }

    public function discardTimer(TimeEntry $entry, User $actor): void
    {
        Gate::forUser($actor)->authorize('stop', $entry);

        DB::transaction(function () use ($entry, $actor) {
            $entry = TimeEntry::lockForUpdate()->findOrFail($entry->id);
            if (! $entry->isRunning()) {
                throw new RuleViolation('This timer has already been stopped.');
            }
            Audit::record('time.timer_discarded', "Timer discarded on {$entry->matter->reference}", $entry, actor: $actor);
            $entry->delete(); // nothing was recorded yet
        });
    }

    /** Corrects an unbilled entry. The audit log keeps what it said before. */
    public function update(TimeEntry $entry, array $data, User $actor): TimeEntry
    {
        Gate::forUser($actor)->authorize('update', $entry);
        $clean = $this->clean($data);

        return DB::transaction(function () use ($entry, $clean, $actor) {
            $entry = TimeEntry::lockForUpdate()->findOrFail($entry->id);
            if (! $entry->isOpen()) {
                throw new RuleViolation('Only unbilled time can be changed. Take it off the draft invoice first.');
            }
            $before = ['worked_on' => $entry->worked_on->toDateString()] + $entry->only('minutes', 'description', 'billable');
            $entry->forceFill($clean)->save();
            Audit::record('time.updated', "Time entry on {$entry->matter->reference} changed", $entry,
                ['before' => $before, 'after' => $clean], actor: $actor);

            return $entry;
        });
    }

    public function void(TimeEntry $entry, string $reason, User $actor): void
    {
        Gate::forUser($actor)->authorize('void', $entry);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new RuleViolation('Record why the time entry is voided.');
        }

        DB::transaction(function () use ($entry, $reason, $actor) {
            $entry = TimeEntry::lockForUpdate()->findOrFail($entry->id);
            if (! $entry->isOpen()) {
                throw new RuleViolation('This entry is voided or on an invoice already.');
            }
            $entry->forceFill(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason])->save();
            Audit::record('time.voided', "Time entry on {$entry->matter->reference} voided: {$reason}", $entry, actor: $actor);
        });
    }

    /** Sets (or replaces) a rate. A matter rate overrides the default on that matter only. */
    public function setRate(User $staff, ?Matter $matter, string $currency, int $hourlyMinor, User $actor): BillingRate
    {
        Gate::forUser($actor)->authorize('create', BillingRate::class);
        $currency = Money::assertCurrency($currency);
        if ($hourlyMinor <= 0) {
            throw new RuleViolation('Enter an hourly rate above zero.');
        }
        if (! $staff->isActive() || ! $staff->hasRole(...self::RECORDING_ROLES)) {
            throw new RuleViolation('Rates are set for active lawyers, case officers and administrators, who record time.');
        }

        return DB::transaction(function () use ($staff, $matter, $currency, $hourlyMinor, $actor) {
            $rate = BillingRate::where('user_id', $staff->id)->where('currency', $currency)
                ->where('matter_id', $matter?->id)->lockForUpdate()->first();
            $before = $rate?->hourly_minor;
            $rate ??= new BillingRate(['user_id' => $staff->id, 'matter_id' => $matter?->id, 'currency' => $currency, 'created_by' => $actor->id]);
            $rate->hourly_minor = $hourlyMinor;
            $rate->save();
            $where = $matter ? "on {$matter->reference}" : 'by default';
            Audit::record('rate.set', "Hourly rate for {$staff->name} {$where}: ".Money::format($hourlyMinor, $currency), $rate,
                ['before' => ['hourly_minor' => $before], 'after' => ['hourly_minor' => $hourlyMinor, 'currency' => $currency]], actor: $actor);

            return $rate;
        });
    }

    public function removeRate(BillingRate $rate, User $actor): void
    {
        Gate::forUser($actor)->authorize('delete', $rate);
        Audit::record('rate.removed', "Hourly rate removed for {$rate->user->name} (".Money::format($rate->hourly_minor, $rate->currency).')', $rate,
            ['before' => $rate->only('user_id', 'matter_id', 'currency', 'hourly_minor')], actor: $actor);
        $rate->delete();
    }

    public static function rateFor(int $userId, int $matterId, string $currency): ?int
    {
        return BillingRate::where('user_id', $userId)->where('currency', $currency)
            ->where(fn ($q) => $q->where('matter_id', $matterId)->orWhereNull('matter_id'))
            ->orderByRaw('matter_id is null') // the matter's own rate first
            ->value('hourly_minor');
    }

    /** Rounded to the nearest minor unit. */
    public static function amount(int $minutes, int $hourlyMinor): int
    {
        return intdiv($minutes * $hourlyMinor + 30, 60);
    }

    /** @return array<int, string> billable time for this client that could go on an invoice in this currency */
    public static function billableOptions(int $clientId, string $currency, ?int $invoiceId = null): array
    {
        return TimeEntry::query()->where('client_id', $clientId)
            ->where('billable', true)->whereNull('voided_at')->whereNotNull('minutes')
            ->where(fn ($q) => $q->whereNull('invoice_id')->when($invoiceId, fn ($q) => $q->orWhere('invoice_id', $invoiceId)))
            ->with(['user', 'matter'])->orderBy('worked_on')->orderBy('id')->get()
            ->mapWithKeys(function (TimeEntry $e) use ($currency) {
                $rate = self::rateFor($e->user_id, $e->matter_id, $currency);
                $price = $rate ? Money::format(self::amount($e->minutes, $rate), $currency) : "no {$currency} rate";

                return [$e->id => "{$e->worked_on->format('j M')} · {$e->matter->reference} · {$e->user->name} · ".TimeEntry::duration($e->minutes)
                    ." · {$e->description} · {$price}"];
            })->all();
    }

    /**
     * Invoice rows for the given entries, which are locked and marked as on the invoice. Called by
     * Invoices while it rewrites a draft's lines.
     *
     * @return list<array<string, mixed>>
     */
    public static function attach(Invoice $invoice, array $entryIds): array
    {
        $entryIds = array_values(array_unique(array_map('intval', $entryIds)));
        if ($entryIds === []) {
            return [];
        }
        $entries = TimeEntry::whereIn('id', $entryIds)->with('user')->lockForUpdate()->orderBy('worked_on')->orderBy('id')->get();
        if ($entries->count() !== count($entryIds)) {
            throw new RuleViolation('One of the selected time entries no longer exists.');
        }

        $rows = [];
        foreach ($entries as $entry) {
            if ($entry->client_id !== $invoice->client_id || ! $entry->billable || $entry->voided_at || $entry->minutes === null
                || ($entry->invoice_id && $entry->invoice_id !== $invoice->id)) {
                throw new RuleViolation("The time entry of {$entry->worked_on->format('j M Y')} cannot go on this invoice (different client, not billable, voided, running or already billed).");
            }
            $rate = self::rateFor($entry->user_id, $entry->matter_id, $invoice->currency);
            if (! $rate) {
                throw new RuleViolation("{$entry->user->name} has no hourly rate in {$invoice->currency}. Set one under Billing → Hourly rates.");
            }
            $amount = self::amount($entry->minutes, $rate);
            $entry->forceFill(['invoice_id' => $invoice->id, 'billed_rate_minor' => $rate, 'billed_minor' => $amount])->save();
            $description = "{$entry->worked_on->format('j M Y')} · {$entry->user->name} · ".TimeEntry::duration($entry->minutes)
                .' at '.Money::format($rate, $invoice->currency).'/h'.($entry->description !== '' ? ": {$entry->description}" : '');
            $rows[] = ['kind' => 'fee', 'description' => mb_substr($description, 0, 300), 'quantity' => 1, 'unit_minor' => $amount, 'amount_minor' => $amount, 'expense_id' => null, 'time_entry_id' => $entry->id];
        }

        return $rows;
    }

    /** Takes every entry off a draft (before its lines are rewritten, or when it is discarded). */
    public static function detach(Invoice $invoice): void
    {
        TimeEntry::where('invoice_id', $invoice->id)->update(['invoice_id' => null, 'billed_rate_minor' => null, 'billed_minor' => null]);
    }

    /**
     * A draft invoice for the chosen entries. They must all be for one client.
     *
     * @param  Collection<int, TimeEntry>  $entries
     */
    public function draftInvoice(Collection $entries, string $currency, User $actor): Invoice
    {
        if ($entries->isEmpty()) {
            throw new RuleViolation('Choose the time to bill.');
        }
        if ($entries->pluck('client_id')->unique()->count() > 1) {
            throw new RuleViolation('The selected time is for more than one client. Bill one client at a time.');
        }
        $entries->loadMissing(['matter', 'client']);
        $matters = $entries->pluck('matter_id')->unique();
        $matter = $matters->count() === 1 ? $entries->first()->matter : null;

        return app(Invoices::class)->createDraft($entries->first()->client, [
            'title' => $matter ? "Professional fees – {$matter->title}" : 'Professional fees',
            'currency' => $currency,
            'matter_id' => $matter?->id,
            'due_date' => Invoices::dueDateDefault(),
            'time_entry_ids' => $entries->pluck('id')->all(),
        ], $actor);
    }

    private function clean(array $data): array
    {
        $minutes = (int) ($data['minutes'] ?? 0);
        if ($minutes < 1 || $minutes > self::MAX_MINUTES) {
            throw new RuleViolation('Enter between 1 minute and 24 hours.');
        }
        $day = (string) ($data['worked_on'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            throw new RuleViolation('Enter the date the work was done.');
        }
        if ($day > $this->today()) {
            throw new RuleViolation('The date cannot be in the future.');
        }

        return [
            'worked_on' => $day,
            'minutes' => $minutes,
            'description' => $this->description((string) ($data['description'] ?? '')),
            'billable' => (bool) ($data['billable'] ?? true),
        ];
    }

    private function description(string $description): string
    {
        $description = trim($description);
        if ($description === '') {
            throw new RuleViolation('Say what the time was spent on.');
        }

        return mb_substr($description, 0, 300);
    }

    private function today(): string
    {
        return now(config('app.firm_timezone'))->toDateString();
    }
}
