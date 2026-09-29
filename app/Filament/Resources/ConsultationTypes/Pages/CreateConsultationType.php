<?php

namespace App\Filament\Resources\ConsultationTypes\Pages;

use App\Domain\Consultations\Consultations;
use App\Filament\Resources\ConsultationTypes\ConsultationTypeResource;
use App\Filament\Support\DomainActions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateConsultationType extends CreateRecord
{
    protected static string $resource = ConsultationTypeResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return DomainActions::save(fn () => app(Consultations::class)->saveType(null, $data, auth()->user()));
    }

    protected function getRedirectUrl(): string
    {
        return ConsultationTypeResource::getUrl('index');
    }
}
