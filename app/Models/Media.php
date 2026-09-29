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
        // Images brought over from the old site are plain files under public/images (DECISIONS D3, D41).
        return $this->disk === 'legacy'
            ? url($this->path)
            : Storage::disk($this->disk)->url($this->path);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }
}
