<?php

namespace App\Filament\Resources\Media\Pages;

use App\Filament\Concerns\AuditsRecordChanges;
use App\Filament\Resources\Media\MediaResource;
use App\Models\Media;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CreateMedia extends CreateRecord
{
    use AuditsRecordChanges;

    protected static string $resource = MediaResource::class;

    /** File facts are read from the stored file, never taken from the browser. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $disk = Storage::disk('media');
        $absolute = $disk->path($data['path']);

        $mime = mime_content_type($absolute) ?: 'application/octet-stream';
        if (! in_array($mime, MediaResource::ACCEPTED_TYPES, true)) {
            $disk->delete($data['path']);
            throw ValidationException::withMessages(['data.path' => 'This type of file is not allowed.']);
        }

        $sha = hash_file('sha256', $absolute);
        if ($existing = Media::where('sha256', $sha)->first()) {
            $disk->delete($data['path']);
            throw ValidationException::withMessages([
                'data.path' => "This file is already in the library as “{$existing->original_name}”.",
            ]);
        }

        [$width, $height] = str_starts_with($mime, 'image/') ? (@getimagesize($absolute) ?: [null, null]) : [null, null];

        return $data + [
            'disk' => 'media',
            'mime_type' => $mime,
            'size' => filesize($absolute),
            'sha256' => $sha,
            'width' => $width,
            'height' => $height,
            'uploaded_by' => auth()->id(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
