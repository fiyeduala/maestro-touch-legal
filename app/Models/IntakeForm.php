<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A published intake form version is never edited; changes create the next version. */
class IntakeForm extends Model
{
    public const FIELD_TYPES = [
        'text' => 'Short text',
        'textarea' => 'Long text',
        'date' => 'Date',
        'select' => 'Choice (one)',
        'number' => 'Number',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'published_at' => 'datetime'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }
}
