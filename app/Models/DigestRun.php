<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DigestRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cutoff_at' => 'datetime', 'window_start' => 'datetime'];
    }

    public function digests(): HasMany
    {
        return $this->hasMany(Digest::class);
    }
}
