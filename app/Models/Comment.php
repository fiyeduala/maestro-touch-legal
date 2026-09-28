<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Imported WordPress comments. New public commenting stays off unless the owner approves it. */
class Comment extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['author_email'];

    protected function casts(): array
    {
        return ['posted_at' => 'datetime'];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
