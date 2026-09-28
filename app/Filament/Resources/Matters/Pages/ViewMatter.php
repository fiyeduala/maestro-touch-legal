<?php

namespace App\Filament\Resources\Matters\Pages;

use App\Filament\Resources\Matters\MatterResource;
use App\Filament\Support\RefreshesRecord;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewMatter extends ViewRecord
{
    use RefreshesRecord;

    protected static string $resource = MatterResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['client', 'service', 'enquiry', 'engagement', 'responsible.user', 'parties']);
    }

    protected function getHeaderActions(): array
    {
        return $this->refreshingAfter(MatterResource::workActions());
    }
}
