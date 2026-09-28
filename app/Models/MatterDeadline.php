<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Manually entered. Nothing here is calculated from statute. */
class MatterDeadline extends Model
{
    public const KINDS = ['milestone' => 'Milestone', 'deadline' => 'Legal deadline (manual)'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'completed_at' => 'datetime', 'client_visible' => 'boolean'];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }
}
