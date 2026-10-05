<?php

namespace App\Filament\Resources\Meetings\Pages;

use App\Filament\Resources\Meetings\MeetingResource;
use App\Filament\Support\RefreshesRecord;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewMeeting extends ViewRecord
{
    use RefreshesRecord;

    protected static string $resource = MeetingResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['organiser', 'matter', 'client', 'participants']);
    }

    protected function getHeaderActions(): array
    {
        return $this->refreshingAfter(MeetingResource::workActions());
    }
}
