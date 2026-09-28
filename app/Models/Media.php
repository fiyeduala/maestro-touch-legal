<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** Public website media only. Confidential client/matter files never use this model. */
class Media extends Model
{
    protected $guarded = ['id'];

    public function url(): string
    {
        // Legacy WordPress uploads are served from public/wp-content/uploads (DECISIONS D3).
        return $this->disk === 'legacy'
            ? url($this->path)
            : Storage::disk($this->disk)->url($this->path);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }
}
