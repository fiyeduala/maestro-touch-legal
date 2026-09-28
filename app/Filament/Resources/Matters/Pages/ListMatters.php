<?php

namespace App\Filament\Resources\Matters\Pages;

use App\Filament\Resources\Matters\MatterResource;
use Filament\Resources\Pages\ListRecords;

/** Matters open only through approval of accepted engagement terms; there is no direct "create". */
class ListMatters extends ListRecords
{
    protected static string $resource = MatterResource::class;
}
