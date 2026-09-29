<?php

namespace App\Filament\Resources\ConsultationTypes\Pages;

use App\Domain\Consultations\Consultations;
use App\Filament\Resources\ConsultationTypes\ConsultationTypeResource;
use App\Filament\Support\DomainActions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/** Types are deactivated, never deleted: past bookings keep pointing at them. */
class EditConsultationType extends EditRecord
{
    protected static string $resource = ConsultationTypeResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return ConsultationTypeResource::fillFrom($this->record);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DomainActions::save(fn () => app(Consultations::class)->saveType($record, $data, auth()->user()));
    }
}
