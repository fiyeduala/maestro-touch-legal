<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class QuotationVersion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'payment_stages' => 'array',
            'fees_minor' => 'integer',
            'expenses_minor' => 'integer',
            'total_minor' => 'integer',
            'valid_until' => 'date',
            'sent_at' => 'datetime',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function acceptances(): MorphMany
    {
        return $this->morphMany(Acceptance::class, 'acceptable');
    }

    public function isFrozen(): bool
    {
        return $this->sent_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->endOfDay()->isPast();
    }

    /** Canonical content used for the acceptance hash: what the client saw and agreed to. */
    public function canonicalContent(string $currency): array
    {
        return [
            'currency' => $currency,
            'scope' => $this->scope,
            'exclusions' => $this->exclusions,
            'lines' => $this->lines,
            'payment_stages' => $this->payment_stages,
            'total_minor' => $this->total_minor,
            'valid_until' => $this->valid_until?->toDateString(),
            'notes' => $this->notes,
        ];
    }
}
