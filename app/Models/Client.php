<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    public function relationshipOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'relationship_owner_id');
    }

    public function isOrganisation(): bool
    {
        return $this->type === 'organisation';
    }
}
