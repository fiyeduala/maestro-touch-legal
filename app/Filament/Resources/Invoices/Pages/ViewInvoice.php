<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\RefreshesRecord;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewInvoice extends ViewRecord
{
    use RefreshesRecord;

    protected static string $resource = InvoiceResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        $invoice = parent::resolveRecord($key)->load(['client', 'matter', 'quotation', 'issuedBy', 'lines', 'allocations.payment', 'creditNotes']);
        $invoice->lines->each->setRelation('invoice', $invoice);

        return $invoice;
    }

    protected function getHeaderActions(): array
    {
        return $this->refreshingAfter(InvoiceResource::workActions());
    }
}
