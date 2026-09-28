<?php

namespace App\Filament\Resources\Engagements\Pages;

use App\Filament\Resources\Engagements\EngagementResource;
use Filament\Resources\Pages\ListRecords;

/** Engagement terms are prepared from an enquiry (Enquiries → Prepare engagement terms). */
class ListEngagements extends ListRecords
{
    protected static string $resource = EngagementResource::class;
}
