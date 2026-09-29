<?php

namespace App\Models;

use App\Domain\Identity\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'billable' => 'boolean', 'incurred_on' => 'date', 'voided_at' => 'datetime'];
    }

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

    /** Billable, not voided and not yet on an invoice. */
    public function scopeUnbilled(Builder $query): Builder
    {
        return $query->where('billable', true)->whereNull('voided_at')->whereNull('invoice_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
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
