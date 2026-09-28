<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Domain\Content\PostEditor;
use App\Filament\Concerns\AuditsRecordChanges;
use App\Filament\Resources\Posts\PostResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePost extends CreateRecord
{
    use AuditsRecordChanges;

    protected static string $resource = PostResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return app(PostEditor::class)->prepare($data, auth()->user());
    }

    protected function afterCreate(): void
    {
        app(PostEditor::class)->afterSave($this->getRecord(), auth()->user(), 'created');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
