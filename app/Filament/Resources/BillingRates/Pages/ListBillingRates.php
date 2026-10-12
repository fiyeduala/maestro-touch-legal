<?php

namespace App\Filament\Resources\BillingRates\Pages;

use App\Filament\Resources\BillingRates\BillingRateResource;
use Filament\Resources\Pages\ListRecords;

class ListBillingRates extends ListRecords
{
    protected static string $resource = BillingRateResource::class;

    protected function getHeaderActions(): array
    {
        return [BillingRateResource::setAction()];
    }
}
