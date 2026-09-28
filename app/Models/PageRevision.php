<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;

class PageRevision extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'noindex' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /** Dot-path lookup into the content JSON, e.g. c('hero.heading'). */
    public function c(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->content ?? [], $key, $default);
    }
}
