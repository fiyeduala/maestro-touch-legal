<?php

namespace App\Models;

use App\Domain\Recruitment\ApplicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StaffApplication extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['applicant_token_hash'];

    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'practice_areas' => 'array',
            'consent_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findByApplicantToken(string $token): ?self
    {
        return static::where('applicant_token_hash', self::hashToken($token))->first();
    }

    public function files(): HasMany
    {
        return $this->hasMany(StaffApplicationFile::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(StaffApplicationEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function applicantVisibleEvents(): HasMany
    {
        return $this->events()->where('is_internal', false);
    }

    public function assignedReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_reviewer_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(Invitation::class);
    }
}
