<?php

namespace App\Models;

use App\Domain\Identity\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Consultation extends Model
{
    public const STATUSES = [
        'requested' => 'Requested',
        'confirmed' => 'Confirmed',
        'cancelled' => 'Cancelled',
        'completed' => 'Completed',
        'no_show' => 'Did not attend',
    ];

    /** Statuses that occupy a slot. */
    public const ACTIVE = ['requested', 'confirmed'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reminders_sent' => 'array',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE);
    }

    /** Staff visibility; see ConsultationPolicy. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->isActive()) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->isFullAdministrator()) {
            return $query;
        }
        if (! $user->hasRole(Role::Lawyer, Role::CaseOfficer)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn (Builder $q) => $q->where('host_id', $user->id)
            ->orWhereHas('enquiry', fn (Builder $e) => $e->where('owner_id', $user->id))
            ->orWhereIn('matter_id', Matter::visibleTo($user)->select('id')));
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ConsultationType::class, 'consultation_type_id');
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

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
