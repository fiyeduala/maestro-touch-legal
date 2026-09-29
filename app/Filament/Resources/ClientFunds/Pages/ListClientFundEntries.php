<?php

namespace App\Filament\Resources\ClientFunds\Pages;

use App\Filament\Resources\ClientFunds\ClientFundEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListClientFundEntries extends ListRecords
{
    protected static string $resource = ClientFundEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [ClientFundEntryResource::recordAction(), ClientFundEntryResource::reconcileAction()];
    }
}
