<?php

namespace App\Filament\Resources\EngagementTemplates\Pages;

use App\Domain\Intake\ServiceCatalogue;
use App\Filament\Resources\EngagementTemplates\EngagementTemplateResource;
use App\Filament\Support\DomainActions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/** Templates are deactivated, never deleted: prepared terms record which template version they came from. */
class EditEngagementTemplate extends EditRecord
{
    protected static string $resource = EngagementTemplateResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DomainActions::save(fn () => app(ServiceCatalogue::class)->saveTemplate($record, $data, auth()->user()));
    }
}
