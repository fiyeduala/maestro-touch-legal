<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    public const STATUSES = [
        'draft' => 'Draft',
        'review' => 'In review',
        'scheduled' => 'Scheduled',
        'published' => 'Published',
        'private' => 'Private',
        'archived' => 'Archived',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'content_modified_at' => 'datetime',
            'comments_visible' => 'boolean',
        ];
    }

    /** Publicly visible: published, or scheduled whose time has come (cron may lag up to 5 min). */
    public function scopeVisible(Builder $q): Builder
    {
        return $q->whereIn('status', ['published', 'scheduled'])
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function isVisible(): bool
    {
        return in_array($this->status, ['published', 'scheduled'], true)
            && $this->published_at !== null && $this->published_at->lte(now());
    }

    public function url(): string
    {
        return url('/'.$this->slug.'/');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)->orderBy('posted_at');
    }

    public function approvedComments(): HasMany
    {
        return $this->comments()->where('status', 'approved');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(PostRevision::class)->latest('created_at');
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function byline(): string
    {
        return $this->author_name ?: ($this->author?->name ?? 'Maestro Touch Legal');
    }
}
