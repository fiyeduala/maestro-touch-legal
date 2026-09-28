<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Page extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(PageRevision::class)->orderByDesc('number');
    }

    public function draftRevision(): BelongsTo
    {
        return $this->belongsTo(PageRevision::class, 'draft_revision_id');
    }

    public function publishedRevision(): BelongsTo
    {
        return $this->belongsTo(PageRevision::class, 'published_revision_id');
    }

    public static function findByPath(string $path): ?self
    {
        return static::where('path', $path)->first();
    }
}
