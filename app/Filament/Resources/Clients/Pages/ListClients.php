<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use Filament\Resources\Pages\ListRecords;

/** Clients are created from enquiries (Enquiries → Create client record). */
class ListClients extends ListRecords
{
    protected static string $resource = ClientResource::class;
}
