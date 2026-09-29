<?php

namespace App\Filament\Resources\Consultations\Pages;

use App\Filament\Resources\Consultations\ConsultationResource;
use App\Filament\Support\RefreshesRecord;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewConsultation extends ViewRecord
{
    use RefreshesRecord;

    protected static string $resource = ConsultationResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['type', 'host', 'client', 'enquiry', 'matter']);
    }

    protected function getHeaderActions(): array
    {
        return $this->refreshingAfter(ConsultationResource::workActions());
    }
}
