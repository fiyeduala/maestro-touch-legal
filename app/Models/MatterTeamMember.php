<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatterTeamMember extends Model
{
    public const ROLES = ['responsible' => 'Responsible lawyer', 'member' => 'Supporting team member'];

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['added_at' => 'datetime', 'removed_at' => 'datetime'];
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }
}
