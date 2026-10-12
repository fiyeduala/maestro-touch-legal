<?php

namespace App\Models;

use App\Domain\Billing\InvoiceStatus;
use App\Domain\Identity\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Minutes a staff member spent on a matter (D53). A running timer has started_at set and no minutes yet. */
class TimeEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'worked_on' => 'date',
            'minutes' => 'integer',
            'started_at' => 'datetime',
            'billable' => 'boolean',
            'billed_rate_minor' => 'integer',
            'billed_minor' => 'integer',
            'voided_at' => 'datetime',
        ];
    }

    /** Same reach as expenses: finance and full administrators see all; case staff the matters they are on. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isFullAdministrator() || ($user->isActive() && $user->hasRole(Role::FinanceOfficer))) {
            return $query;
        }
        if (! $user->isActive() || ! $user->hasRole(Role::Lawyer, Role::CaseOfficer)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('matter', fn (Builder $m) => $m->whereHas('activeTeam', fn (Builder $t) => $t->where('user_id', $user->id)));
    }

    /** Billable, finished, not voided and not yet on an invoice. */
    public function scopeUnbilled(Builder $query): Builder
    {
        return $query->where('billable', true)->whereNull('voided_at')->whereNull('invoice_id')->whereNotNull('minutes');
    }

    public function scopeRunning(Builder $query): Builder
    {
        return $query->whereNotNull('started_at')->whereNull('minutes')->whereNull('voided_at');
    }

    public function isRunning(): bool
    {
        return $this->started_at !== null && $this->minutes === null && $this->voided_at === null;
    }

    /** Finished, not voided and not on any invoice, so it can still be changed. */
    public function isOpen(): bool
    {
        return ! $this->isRunning() && $this->voided_at === null && $this->invoice_id === null;
    }

    /** Where the entry stands, for lists. */
    public function statusLabel(): string
    {
        return match (true) {
            $this->voided_at !== null => 'Voided',
            $this->isRunning() => 'Timer running',
            $this->invoice_id !== null => ($this->invoice?->status === InvoiceStatus::Draft ? 'On draft ' : 'Billed on ').$this->invoice?->reference,
            ! $this->billable => 'Not billable',
            default => 'Not billed',
        };
    }

    public function statusColor(): string
    {
        return match (true) {
            $this->voided_at !== null, ! $this->billable => 'gray',
            $this->isRunning() => 'warning',
            $this->invoice_id !== null => $this->invoice?->status === InvoiceStatus::Draft ? 'info' : 'success',
            default => 'primary',
        };
    }

    /** "1h 30m", "45m", "2h". */
    public static function duration(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return trim(($h ? "{$h}h " : '').($m || ! $h ? "{$m}m" : ''));
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
