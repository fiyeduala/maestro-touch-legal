<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Domain\Operations\Audit;
use App\Filament\Resources\Categories\CategoryResource;
use App\Models\Category;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCategories extends ManageRecords
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->after(fn (Category $record) => Audit::record('category.created', "Created category {$record->name}", $record)),
        ];
    }
}
