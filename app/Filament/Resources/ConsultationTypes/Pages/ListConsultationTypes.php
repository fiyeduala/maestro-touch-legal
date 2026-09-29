<?php

namespace App\Filament\Resources\ConsultationTypes\Pages;

use App\Filament\Resources\ConsultationTypes\ConsultationTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListConsultationTypes extends ListRecords
{
    protected static string $resource = ConsultationTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
