<?php

namespace App\Models;

use App\Domain\Billing\PaymentStatus;
use App\Domain\Identity\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    public const METHODS = [
        'paystack' => 'Paystack (online)',
        'bank_transfer' => 'Bank transfer',
        'cash' => 'Cash',
        'other' => 'Other',
    ];

    protected $guarded = ['id'];

    protected $hidden = ['evidence_path'];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount_minor' => 'integer',
            'received_minor' => 'integer',
            'unapplied_minor' => 'integer',
            'refunded_minor' => 'integer',
            'refund_pending_minor' => 'integer',
            'received_on' => 'date',
            'paid_at' => 'datetime',
            'verified_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    /** Mirrors PaymentPolicy::view for lists: case staff see payments on their matters' invoices. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isFullAdministrator() || ($user->isActive() && $user->hasRole(Role::FinanceOfficer))) {
            return $query;
        }
        if (! $user->isActive() || ! $user->hasRole(Role::Lawyer, Role::CaseOfficer)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('invoice.matter', fn (Builder $m) => $m->whereHas('activeTeam', fn (Builder $t) => $t->where('user_id', $user->id)));
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? $this->method;
    }

    /** Received money not (yet) allocated and not refunded: usable as client credit. */
    public function availableCreditMinor(): int
    {
        return $this->status === PaymentStatus::Succeeded ? $this->unapplied_minor : 0;
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class)->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class)->orderBy('id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
