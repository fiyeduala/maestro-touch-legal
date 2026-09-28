<?php

namespace App\Models;

use App\Domain\Engagement\OfferStatus;
use App\Domain\Identity\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Engagement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => OfferStatus::class, 'approved_at' => 'datetime'];
    }

    /** Mirrors EngagementPolicy::view for lists. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isFullAdministrator()) {
            return $query;
        }
        if (! $user->isActive() || ! $user->hasRole(Role::Lawyer)) {
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

    public function quotationVersion(): BelongsTo
    {
        return $this->belongsTo(QuotationVersion::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(EngagementVersion::class)->orderByDesc('version');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(EngagementVersion::class, 'current_version_id');
    }

    public function acceptedVersion(): BelongsTo
    {
        return $this->belongsTo(EngagementVersion::class, 'accepted_version_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
