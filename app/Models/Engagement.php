<?php

namespace App\Models;

use App\Domain\Engagement\OfferStatus;
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
