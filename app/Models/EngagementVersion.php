<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class EngagementVersion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    public function acceptances(): MorphMany
    {
        return $this->morphMany(Acceptance::class, 'acceptable');
    }

    public function isFrozen(): bool
    {
        return $this->sent_at !== null;
    }
}
