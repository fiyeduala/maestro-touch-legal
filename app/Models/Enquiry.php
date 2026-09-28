<?php

namespace App\Models;

use App\Domain\Identity\Role;
use App\Domain\Intake\EnquirySource;
use App\Domain\Intake\EnquiryStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Enquiry extends Model
{
    protected $guarded = ['id'];

    /** Mirrors the column default so a new enquiry never reads as anything but pending. */
    protected $attributes = ['conflict_status' => 'pending'];

    protected function casts(): array
    {
        return [
            'status' => EnquiryStatus::class,
            'source' => EnquirySource::class,
            'answers' => 'array',
            'follow_up_on' => 'date',
            'conflict_reviewed_at' => 'datetime',
            'closed_at' => 'datetime',
            'converted_at' => 'datetime',
            'consent_at' => 'datetime',
        ];
    }

    /** Full administrators see every enquiry; other staff only those they own (permission matrix note ¹). */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isFullAdministrator()) {
            return $query;
        }
        if ($user->isActive() && $user->hasRole(Role::Lawyer, Role::CaseOfficer)) {
            return $query->where('owner_id', $user->id);
        }

        return $query->whereRaw('1 = 0');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function intakeForm(): BelongsTo
    {
        return $this->belongsTo(IntakeForm::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(EnquiryEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function parties(): HasMany
    {
        return $this->hasMany(Party::class)->orderBy('id');
    }

    public function conflictReviews(): HasMany
    {
        return $this->hasMany(ConflictReview::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function engagements(): HasMany
    {
        return $this->hasMany(Engagement::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function isConflictCleared(): bool
    {
        return $this->conflict_status === 'cleared';
    }
}
