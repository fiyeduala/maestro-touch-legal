<?php

namespace App\Filament\Resources\StaffApplications\Pages;

use App\Filament\Resources\StaffApplications\StaffApplicationResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewStaffApplication extends ViewRecord
{
    protected static string $resource = StaffApplicationResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['files', 'events.actor', 'assignedReviewer']);
    }

    protected function getHeaderActions(): array
    {
        // Reload after each action so the status, files and history shown are current.
        return array_map(
            fn (Action $action) => $action->after(fn () => $this->record = $this->resolveRecord($this->record->getKey())),
            StaffApplicationResource::reviewActions(),
        );
    }
}
