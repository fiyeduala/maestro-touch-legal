<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class DocumentVersion extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['path', 'disk'];

    protected function casts(): array
    {
        return ['uploaded_by_client' => 'boolean', 'created_at' => 'datetime', 'size' => 'integer'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function acceptances(): MorphMany
    {
        return $this->morphMany(Acceptance::class, 'acceptable');
    }

    /** Only these are ever shown inline; everything else is a forced download. */
    public function isSafeInline(): bool
    {
        return in_array($this->mime_type, ['application/pdf', 'image/png', 'image/jpeg', 'image/webp'], true);
    }
}
