<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Support\RefreshesRecord;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewQuotation extends ViewRecord
{
    use RefreshesRecord;

    protected static string $resource = QuotationResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['client', 'enquiry', 'matter', 'currentVersion', 'versions.acceptances.user']);
    }

    protected function getHeaderActions(): array
    {
        return $this->refreshingAfter(QuotationResource::workActions());
    }
}
