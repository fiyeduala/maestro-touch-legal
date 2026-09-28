<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EngagementTemplate extends Model
{
    /** Placeholders filled when terms are prepared from a template. */
    public const PLACEHOLDERS = [
        '{client_name}' => 'Client display name',
        '{client_reference}' => 'Client reference',
        '{service}' => 'Service name',
        '{engagement_title}' => 'Engagement title',
        '{firm_name}' => 'Firm name (site title)',
        '{date}' => 'Date prepared',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
