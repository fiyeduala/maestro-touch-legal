<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A scheduled video meeting between staff, or between staff and client contacts (D50). */
class Meeting extends Model
{
    public const STATUSES = [
        'scheduled' => 'Scheduled',
        'cancelled' => 'Cancelled',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }

    /** Full administrators see every meeting; everyone else sees meetings they organise or are invited to. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->isActive()) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->isFullAdministrator()) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q->where('organiser_id', $user->id)
            ->orWhereHas('participants', fn (Builder $p) => $p->whereKey($user->id)));
    }

    public function isScheduled(): bool
    {
        return $this->status === 'scheduled';
    }

    public function statusLabel(): string
    {
        if ($this->isScheduled() && $this->ends_at->isPast()) {
            return 'Ended';
        }

        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function hasParticipant(User $user): bool
    {
        return $this->organiser_id === $user->id || $this->participants()->whereKey($user->id)->exists();
    }

    public function organiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organiser_id');
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'meeting_participants')->withTimestamps();
    }
}
