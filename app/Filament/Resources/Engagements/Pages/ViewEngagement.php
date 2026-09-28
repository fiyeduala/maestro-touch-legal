<?php

namespace App\Filament\Resources\Engagements\Pages;

use App\Filament\Resources\Engagements\EngagementResource;
use App\Filament\Support\RefreshesRecord;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewEngagement extends ViewRecord
{
    use RefreshesRecord;

    protected static string $resource = EngagementResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load([
            'client', 'enquiry', 'matter', 'currentVersion', 'approvedBy',
            'versions.acceptances.recordedBy', 'versions.acceptances.evidenceDocument',
        ]);
    }

    protected function getHeaderActions(): array
    {
        return $this->refreshingAfter(EngagementResource::workActions());
    }
}
