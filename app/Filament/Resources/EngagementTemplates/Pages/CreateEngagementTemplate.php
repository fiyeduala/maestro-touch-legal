<?php

namespace App\Filament\Resources\EngagementTemplates\Pages;

use App\Domain\Intake\ServiceCatalogue;
use App\Filament\Resources\EngagementTemplates\EngagementTemplateResource;
use App\Filament\Support\DomainActions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateEngagementTemplate extends CreateRecord
{
    protected static string $resource = EngagementTemplateResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return DomainActions::save(fn () => app(ServiceCatalogue::class)->saveTemplate(null, $data, auth()->user()));
    }

    protected function getRedirectUrl(): string
    {
        return EngagementTemplateResource::getUrl('index');
    }
}
