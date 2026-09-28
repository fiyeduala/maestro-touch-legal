<?php

namespace App\Models;

use App\Domain\Identity\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Client extends Model
{
    protected $guarded = ['id', 'reference'];

    protected static function booted(): void
    {
        static::creating(function (Client $client) {
            $client->reference ??= self::nextReference();
        });
    }

    /** CL-000123, sequential and unique (unique index is the final guard). */
    public static function nextReference(): string
    {
        $last = DB::table('clients')->lockForUpdate()->max('id') ?? 0;

        return sprintf('CL-%06d', $last + 1);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['relationship', 'revoked_at'])
            ->wherePivotNull('revoked_at')
            ->withTimestamps();
    }

    /** Every link, including revoked ones, for the admin history view. */
    public function contactLinks(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['relationship', 'revoked_at', 'added_by', 'revoked_by'])
            ->withTimestamps();
    }

    public function matters(): HasMany
    {
        return $this->hasMany(Matter::class);
    }

    public function enquiries(): HasMany
    {
        return $this->hasMany(Enquiry::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function engagements(): HasMany
    {
        return $this->hasMany(Engagement::class);
    }

    /**
     * Staff below full administrator see a client only through a matter they are on
     * (permission matrix note ²), or an enquiry they own.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isFullAdministrator()) {
            return $query;
        }
        if (! $user->isActive() || ! $user->hasRole(Role::Lawyer, Role::CaseOfficer)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn (Builder $q) => $q
            ->whereHas('matters', fn (Builder $m) => $m->whereHas('activeTeam', fn (Builder $t) => $t->where('user_id', $user->id)))
            ->orWhereHas('enquiries', fn (Builder $e) => $e->where('owner_id', $user->id)));
    }

    public function relationshipOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'relationship_owner_id');
    }

    public function isOrganisation(): bool
    {
        return $this->type === 'organisation';
    }
}
