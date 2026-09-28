<?php

namespace App\Filament\Resources\EngagementTemplates\Pages;

use App\Filament\Resources\EngagementTemplates\EngagementTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEngagementTemplates extends ListRecords
{
    protected static string $resource = EngagementTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
