<?php

namespace App\Domain\Reporting;

use App\Domain\Billing\InvoiceStatus;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Engagement\OfferStatus;
use App\Domain\Intake\EnquiryStatus;
use App\Domain\Matters\MatterStatus;
use App\Domain\Matters\TaskStatus;
use App\Domain\Operations\Settings;
use App\Models\ClientFundEntry;
use App\Models\Consultation;
use App\Models\CreditNote;
use App\Models\Enquiry;
use App\Models\EnquiryEvent;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\Task;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Report figures, always per currency: NGN and USD are never added together (no exchange rates are
 * recorded). Actual receipts, outstanding invoices and quotation estimates are kept apart.
 *
 * Filters: from/until (firm-timezone dates, inclusive), currency, service_id, team_user_id (a current
 * member of the matter team). Service and team filters reach billing through the invoice's matter, so
 * records with no matter drop out when either is set.
 */
class Reports
{
    public const AGEING = ['current' => 'Not yet due', '1_30' => '1–30 days overdue', '31_60' => '31–60 days', '61_90' => '61–90 days', '90_plus' => 'Over 90 days'];

    /**
     * @param  array{from?: ?string, until?: ?string, currency?: ?string, service_id?: ?int, team_user_id?: ?int}  $filters
     * @return array<string, array<string, int>> currency => metric => minor units (or counts)
     */
    public function finance(array $filters): array
    {
        [$from, $until] = $this->range($filters);
        $today = now(Settings::get('firm.timezone'))->toDateString();
        $result = [];

        foreach ($this->currencies($filters) as $currency) {
            $invoices = fn () => $this->matterFilter(Invoice::query()->where('currency', $currency), $filters);

            $issued = $invoices()->whereIn('status', InvoiceStatus::issuedValues())->whereDate('issue_date', '>=', $from->toDateString())->whereDate('issue_date', '<=', $until->toDateString());
            $credits = CreditNote::where('currency', $currency)->whereBetween('created_at', [$from->copy()->utc(), $until->copy()->utc()])
                ->whereHas('invoice', fn (Builder $query) => $this->matterFilter($query, $filters));
            $received = $this->receivedIn($this->paymentFilter(Payment::where('currency', $currency), $filters), $from, $until)
                ->whereIn('status', [PaymentStatus::Succeeded->value]);

            $row = [
                'invoiced' => (int) (clone $issued)->sum('total_minor'),
                'invoiced_count' => (clone $issued)->count(),
                'credited' => (int) $credits->sum('amount_minor'),
                'received' => (int) (clone $received)->sum('received_minor'),
                'received_count' => (clone $received)->count(),
                'refunded' => (int) (clone $received)->sum('refunded_minor'),
                'outstanding' => 0,
                'outstanding_count' => 0,
                'overdue' => 0,
                'unapplied_credit' => (int) $this->paymentFilter(Payment::where('currency', $currency), $filters)
                    ->where('status', PaymentStatus::Succeeded->value)->sum('unapplied_minor'),
                'awaiting_verification' => (int) $this->paymentFilter(Payment::where('currency', $currency), $filters)
                    ->where('status', PaymentStatus::PendingVerification->value)->sum('amount_minor'),
                'awaiting_verification_count' => $this->paymentFilter(Payment::where('currency', $currency), $filters)
                    ->where('status', PaymentStatus::PendingVerification->value)->count(),
                'client_funds_held' => (int) $this->matterFilter(ClientFundEntry::where('currency', $currency), $filters)->sum('amount_minor'),
                'pipeline' => 0,
                'pipeline_count' => 0,
            ] + array_fill_keys(array_keys(self::AGEING), 0);

            foreach ($invoices()->open()->get(['id', 'due_date', 'total_minor', 'paid_minor', 'credited_minor']) as $invoice) {
                $balance = $invoice->balanceMinor();
                $row['outstanding'] += $balance;
                $row['outstanding_count']++;
                $bucket = $this->ageingBucket($invoice->due_date?->toDateString(), $today);
                $row[$bucket] += $balance;
                if ($bucket !== 'current') {
                    $row['overdue'] += $balance;
                }
            }

            $pipeline = $this->matterFilter(Quotation::query()->where('currency', $currency)->where('status', OfferStatus::Sent->value), $filters)
                ->with('currentVersion:id,total_minor')->get();
            $row['pipeline'] = (int) $pipeline->sum(fn (Quotation $q) => $q->currentVersion?->total_minor ?? 0);
            $row['pipeline_count'] = $pipeline->count();

            $result[$currency] = $row;
        }

        return $result;
    }

    /**
     * @param  array{from?: ?string, until?: ?string, service_id?: ?int, team_user_id?: ?int}  $filters
     * @return array<string, mixed>
     */
    public function practice(array $filters): array
    {
        [$from, $until] = $this->range($filters);
        $utc = [$from->copy()->utc(), $until->copy()->utc()];

        $enquiries = Enquiry::query()->whereBetween('created_at', $utc)
            ->when($filters['service_id'] ?? null, fn (Builder $q, $id) => $q->where('service_id', $id))
            ->when($filters['team_user_id'] ?? null, fn (Builder $q, $id) => $q->where('owner_id', $id));
        $byStatus = (clone $enquiries)->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        // First staff action: the first status change away from "new" made by a person (not the system).
        $ids = (clone $enquiries)->pluck('created_at', 'id');
        $firstActions = EnquiryEvent::whereIn('enquiry_id', $ids->keys())->where('type', 'status_changed')
            ->where('from_status', EnquiryStatus::New->value)->whereNotNull('actor_id')
            ->selectRaw('enquiry_id, min(created_at) as first_at')->groupBy('enquiry_id')->pluck('first_at', 'enquiry_id');
        $hours = $firstActions->map(fn ($at, $id) => max(0, Carbon::parse($ids[$id])->diffInMinutes(Carbon::parse($at))) / 60)->values()->sort()->values();

        $matters = fn () => $this->teamFilter(Matter::query(), $filters)
            ->when($filters['service_id'] ?? null, fn (Builder $q, $id) => $q->where('service_id', $id));
        $tasks = fn () => Task::query()->whereHas('matter', fn (Builder $m) => $this->teamFilter($m, $filters)
            ->when($filters['service_id'] ?? null, fn (Builder $q, $id) => $q->where('service_id', $id)));

        return [
            'enquiries_received' => (int) $byStatus->sum(),
            'enquiries_by_status' => collect(EnquiryStatus::cases())->mapWithKeys(fn (EnquiryStatus $s) => [$s->label() => (int) ($byStatus[$s->value] ?? 0)])->all(),
            'enquiries_converted' => (clone $enquiries)->whereNotNull('converted_at')->count(),
            'first_action_count' => $hours->count(),
            'first_action_missing' => $ids->count() - $hours->count(),
            'first_action_median_hours' => $this->percentile($hours, 50),
            'first_action_p90_hours' => $this->percentile($hours, 90),
            'matters_opened' => $matters()->whereBetween('opened_at', $utc)->count(),
            'matters_closed' => $matters()->whereBetween('closed_at', $utc)->count(),
            'matters_active' => $matters()->whereIn('status', [MatterStatus::Open->value, MatterStatus::OnHold->value])->count(),
            'tasks_completed' => $tasks()->where('status', TaskStatus::Done->value)->whereBetween('completed_at', $utc)->count(),
            'tasks_overdue' => $tasks()->overdue()->count(),
            'consultations' => Consultation::query()->whereBetween('starts_at', $utc)
                ->when($filters['team_user_id'] ?? null, fn (Builder $q, $id) => $q->where('host_id', $id))
                ->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')->map(fn ($n) => (int) $n)->all(),
        ];
    }

    /**
     * Invoice rows for CSV export: one row per issued invoice in the period, amounts as decimals in the
     * invoice's own currency (never converted).
     *
     * @return list<array<string, string>>
     */
    public function invoiceRows(array $filters): array
    {
        [$from, $until] = $this->range($filters);
        $today = now(Settings::get('firm.timezone'))->toDateString();

        return $this->matterFilter(Invoice::query(), $filters)
            ->when($filters['currency'] ?? null, fn (Builder $q, $c) => $q->where('currency', $c))
            ->whereIn('status', InvoiceStatus::issuedValues())
            ->whereDate('issue_date', '>=', $from->toDateString())->whereDate('issue_date', '<=', $until->toDateString())
            ->with(['client:id,reference,display_name', 'matter:id,reference'])
            ->orderBy('issue_date')->orderBy('id')->get()
            ->map(fn (Invoice $i) => [
                'Invoice' => $i->reference,
                'Issued' => $i->issue_date?->toDateString() ?? '',
                'Due' => $i->due_date?->toDateString() ?? '',
                'Client' => $i->client?->display_name ?? '',
                'Client ref' => $i->client?->reference ?? '',
                'Matter' => $i->matter?->reference ?? '',
                'Status' => $i->status->label(),
                'Currency' => $i->currency,
                'Total' => Money::input($i->total_minor),
                'Paid' => Money::input($i->paid_minor),
                'Credited' => Money::input($i->credited_minor),
                'Balance' => Money::input($i->balanceMinor()),
                'Ageing' => $i->status->isOpen() ? self::AGEING[$this->ageingBucket($i->due_date?->toDateString(), $today)] : '',
            ])->all();
    }

    public function ageingBucket(?string $dueDate, string $today): string
    {
        if (! $dueDate || $dueDate >= $today) {
            return 'current';
        }
        $days = Carbon::parse($dueDate)->diffInDays(Carbon::parse($today));

        return match (true) {
            $days <= 30 => '1_30',
            $days <= 60 => '31_60',
            $days <= 90 => '61_90',
            default => '90_plus',
        };
    }

    /** @return array{0: Carbon, 1: Carbon} start and end of the range in the firm's timezone */
    public function range(array $filters): array
    {
        $tz = Settings::get('firm.timezone');
        $from = filled($filters['from'] ?? null) ? Carbon::parse($filters['from'], $tz)->startOfDay() : now($tz)->startOfMonth();
        $until = filled($filters['until'] ?? null) ? Carbon::parse($filters['until'], $tz)->endOfDay() : now($tz)->endOfDay();

        return [$from, $until];
    }

    /** @return list<string> */
    private function currencies(array $filters): array
    {
        return filled($filters['currency'] ?? null) ? [Money::assertCurrency($filters['currency'])] : array_keys(Money::CURRENCIES);
    }

    /** Manual receipts count on their statement date; online payments on the date Paystack reports. */
    private function receivedIn(Builder $query, Carbon $from, Carbon $until): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereDate('received_on', '>=', $from->toDateString())->whereDate('received_on', '<=', $until->toDateString())
            ->orWhere(fn (Builder $p) => $p->whereNull('received_on')->whereBetween('paid_at', [$from->copy()->utc(), $until->copy()->utc()])));
    }

    /** For models with a matter_id: records without a matter drop out only when a service or team filter is set. */
    private function matterFilter(Builder $query, array $filters): Builder
    {
        $service = $filters['service_id'] ?? null;
        $member = $filters['team_user_id'] ?? null;
        if (! $service && ! $member) {
            return $query;
        }

        return $query->whereHas('matter', fn (Builder $m) => $this->teamFilter($m, $filters)
            ->when($service, fn (Builder $q, $id) => $q->where('service_id', $id)));
    }

    private function paymentFilter(Builder $query, array $filters): Builder
    {
        if (! ($filters['service_id'] ?? null) && ! ($filters['team_user_id'] ?? null)) {
            return $query;
        }

        return $query->whereHas('invoice', fn (Builder $i) => $this->matterFilter($i, $filters));
    }

    private function teamFilter(Builder $matters, array $filters): Builder
    {
        return $matters->when($filters['team_user_id'] ?? null,
            fn (Builder $q, $id) => $q->whereHas('activeTeam', fn (Builder $t) => $t->where('user_id', $id)));
    }

    private function percentile(Collection $sorted, int $p): ?float
    {
        if ($sorted->isEmpty()) {
            return null;
        }
        $index = (int) ceil($p / 100 * $sorted->count()) - 1;

        return round((float) $sorted[max(0, $index)], 1);
    }
}
