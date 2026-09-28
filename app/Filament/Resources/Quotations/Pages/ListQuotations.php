<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Filament\Resources\Quotations\QuotationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListQuotations extends ListRecords
{
    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        // Case staff start a quotation from their enquiry or matter; this direct route is for administrators and finance.
        return [CreateAction::make()->label('New quotation')
            ->visible(fn () => auth()->user()->isFullAdministrator() || auth()->user()->hasRole(\App\Domain\Identity\Role::FinanceOfficer))];
    }
}
