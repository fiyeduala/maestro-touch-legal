<?php

namespace App\Models;

use App\Domain\Identity\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Append-only ledger of money held for a client. Never firm revenue. */
class ClientFundEntry extends Model
{
    public const UPDATED_AT = null;

    public const TYPES = [
        'receipt' => 'Received for the client',
        'fee_deduction' => 'Fee deducted (authorised)',
        'remittance' => 'Paid out to the client',
        'reversal' => 'Reversal of an entry',
    ];

    protected $guarded = ['id'];

    protected $hidden = ['evidence_path'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'occurred_on' => 'date'];
    }

    /** Lawyers on the matter team can read; case officers cannot (permission matrix). */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isFullAdministrator() || ($user->isActive() && $user->hasRole(Role::FinanceOfficer))) {
            return $query;
        }
        if (! $user->isActive() || ! $user->hasRole(Role::Lawyer)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('matter', fn (Builder $m) => $m->whereHas('activeTeam', fn (Builder $t) => $t->where('user_id', $user->id)));
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_entry_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
