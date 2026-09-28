<?php

namespace App\Filament\Resources\Services\Pages;

use App\Domain\Intake\ServiceCatalogue;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Support\DomainActions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateService extends CreateRecord
{
    protected static string $resource = ServiceResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return DomainActions::save(fn () => app(ServiceCatalogue::class)->saveService(null, $data, auth()->user()));
    }

    protected function getRedirectUrl(): string
    {
        return ServiceResource::getUrl('edit', ['record' => $this->record]);
    }
}
