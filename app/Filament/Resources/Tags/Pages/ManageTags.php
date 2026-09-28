<?php

namespace App\Filament\Resources\Tags\Pages;

use App\Domain\Operations\Audit;
use App\Filament\Resources\Tags\TagResource;
use App\Models\Tag;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTags extends ManageRecords
{
    protected static string $resource = TagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->after(fn (Tag $record) => Audit::record('tag.created', "Created tag {$record->name}", $record)),
        ];
    }
}
