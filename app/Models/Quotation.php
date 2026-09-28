<?php

namespace App\Models;

use App\Domain\Engagement\OfferStatus;
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
