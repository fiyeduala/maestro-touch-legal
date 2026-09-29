<?php

namespace App\Models;

use App\Domain\Billing\InvoiceStatus;
use App\Domain\Identity\Role;
use App\Domain\Operations\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'issue_date' => 'date',
            'due_date' => 'date',
            'fees_minor' => 'integer',
            'expenses_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'paid_minor' => 'integer',
            'credited_minor' => 'integer',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** Mirrors InvoicePolicy::view for lists. */
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

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', InvoiceStatus::openValues());
    }

    public function balanceMinor(): int
    {
        return max(0, $this->total_minor - $this->paid_minor - $this->credited_minor);
    }

    public function isOverdue(): bool
    {
        if (! $this->status->isOpen() || ! $this->due_date) {
            return false;
        }

        return $this->due_date->toDateString() < now(Settings::get('firm.timezone'))->toDateString();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('sort');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest('id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class)->orderBy('id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
