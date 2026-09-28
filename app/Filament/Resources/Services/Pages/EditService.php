<?php

namespace App\Filament\Resources\Services\Pages;

use App\Domain\Intake\ServiceCatalogue;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Support\DomainActions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/** Services are deactivated, never deleted: enquiries and matters keep pointing at them. */
class EditService extends EditRecord
{
    protected static string $resource = ServiceResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DomainActions::save(fn () => app(ServiceCatalogue::class)->saveService($record, $data, auth()->user()));
    }
}
