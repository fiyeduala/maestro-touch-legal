<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Support\RefreshesRecord;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewPayment extends ViewRecord
{
    use RefreshesRecord;

    protected static string $resource = PaymentResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        $payment = parent::resolveRecord($key)->load(['client', 'invoice', 'submittedBy', 'verifiedBy', 'allocations']);
        $payment->allocations->each->setRelation('payment', $payment);

        return $payment;
    }

    protected function getHeaderActions(): array
    {
        return $this->refreshingAfter(PaymentResource::workActions());
    }
}
