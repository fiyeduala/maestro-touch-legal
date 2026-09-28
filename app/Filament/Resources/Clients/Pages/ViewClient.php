<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\RefreshesRecord;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewClient extends ViewRecord
{
    use RefreshesRecord;

    protected static string $resource = ClientResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load('relationshipOwner');
    }

    protected function getHeaderActions(): array
    {
        return $this->refreshingAfter([ClientResource::editAction()]);
    }
}
