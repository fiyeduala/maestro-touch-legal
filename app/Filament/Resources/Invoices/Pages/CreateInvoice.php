<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Domain\Billing\Invoices;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\DomainActions;
use App\Models\Client;
use App\Models\Matter;
use App\Support\Money;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

/** A draft invoice for a client, optionally from a matter (?matter=). Nothing reaches the client until it is issued. */
class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    protected static bool $canCreateAnother = false;

    #[Locked]
    public ?int $matterId = null;

    public function mount(): void
    {
        $this->matterId = request()->integer('matter') ?: null;
        abort_if($this->matterId && ! $this->matter(), 404);

        parent::mount();
    }

    private function matter(): ?Matter
    {
        return $this->matterId ? Matter::query()->linkableForBilling(auth()->user())->find($this->matterId) : null;
    }

    protected function fillForm(): void
    {
        $matter = $this->matter();
        $this->form->fill([
            'client_id' => $matter?->client_id,
            'matter_id' => $matter?->id,
            'currency' => $matter?->client?->preferred_currency ?? 'NGN',
            'title' => $matter ? 'Professional fees – '.$matter->title : null,
            'due_date' => Invoices::dueDateDefault(),
            'lines' => [['kind' => 'fee', 'quantity' => 1]],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Client and currency')->columnSpanFull()->schema([
                Grid::make(3)->schema([
                    Select::make('client_id')->label('Client')->required()->searchable()->live()
                        ->disabled(fn () => (bool) $this->matterId)->dehydrated()
                        ->getSearchResultsUsing(fn (string $search) => InvoiceResource::billingClientSearch($search))
                        ->getOptionLabelUsing(fn ($value) => Client::find($value)?->display_name)
                        ->columnSpan(2),
                    Select::make('currency')->options(Money::currencyOptions())->required()->live()
                        ->helperText('Every amount on this invoice is in this currency. There is no conversion.'),
                ]),
            ]),
            Section::make('Content')->columnSpanFull()->schema(InvoiceResource::contentFields(
                fn () => filled($this->data['client_id'] ?? null) ? (int) $this->data['client_id'] : null,
                fn () => $this->data['currency'] ?? null,
            )),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $client = Client::query()->linkableForBilling(auth()->user())->findOrFail($this->matter()?->client_id ?? $data['client_id']);

        return DomainActions::save(fn () => app(Invoices::class)->createDraft($client, $data, auth()->user()));
    }

    protected function getRedirectUrl(): string
    {
        return InvoiceResource::getUrl('view', ['record' => $this->record]);
    }
}
