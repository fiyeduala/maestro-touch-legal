<?php

namespace App\Models;

use App\Domain\Engagement\OfferStatus;
use App\Domain\Identity\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quotation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => OfferStatus::class];
    }

    /** Mirrors QuotationPolicy::view for lists. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isFullAdministrator() || ($user->isActive() && $user->hasRole(Role::FinanceOfficer))) {
            return $query;
        }
        if (! $user->isActive() || ! $user->hasRole(Role::Lawyer, Role::CaseOfficer)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn (Builder $q) => $q
            ->whereHas('enquiry', fn (Builder $e) => $e->where('owner_id', $user->id))
            ->orWhereHas('matter', fn (Builder $m) => $m->whereHas('activeTeam', fn (Builder $t) => $t->where('user_id', $user->id))));
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(Enquiry::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(QuotationVersion::class)->orderByDesc('version');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(QuotationVersion::class, 'current_version_id');
    }

    public function acceptedVersion(): BelongsTo
    {
        return $this->belongsTo(QuotationVersion::class, 'accepted_version_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
