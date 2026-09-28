<?php

namespace App\Filament\Resources\Enquiries\Pages;

use App\Filament\Resources\Enquiries\EnquiryResource;
use App\Filament\Support\RefreshesRecord;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewEnquiry extends ViewRecord
{
    use RefreshesRecord;

    protected static string $resource = EnquiryResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load([
            'service', 'owner', 'client', 'matter', 'parties', 'conflictReviews.reviewer',
            'quotations', 'engagements', 'events.actor',
        ]);
    }

    protected function getHeaderActions(): array
    {
        return $this->refreshingAfter(EnquiryResource::workActions());
    }
}
