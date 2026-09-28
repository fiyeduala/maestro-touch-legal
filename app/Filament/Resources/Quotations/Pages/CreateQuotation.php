<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Domain\Engagement\Quotations;
use App\Domain\RuleViolation;
use App\Filament\Resources\Enquiries\EnquiryResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\Client;
use App\Models\Enquiry;
use App\Models\Matter;
use App\Support\Money;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

/**
 * A quotation is prepared from an enquiry (?enquiry=) or a matter (?matter=); administrators and finance
 * may also prepare one directly for a client. The domain service re-checks access to the context.
 */
class CreateQuotation extends CreateRecord
{
    protected static string $resource = QuotationResource::class;

    protected static bool $canCreateAnother = false;

    #[Locked]
    public ?int $enquiryId = null;

    #[Locked]
    public ?int $matterId = null;

    public function mount(): void
    {
        $this->enquiryId = request()->integer('enquiry') ?: null;
        $this->matterId = request()->integer('matter') ?: null;
        abort_if($this->enquiry() === false || $this->matter() === false, 404);

        parent::mount();
    }

    /** @return Enquiry|null|false false when the id is given but not visible */
    private function enquiry(): Enquiry|null|false
    {
        return $this->enquiryId ? (Enquiry::query()->visibleTo(auth()->user())->find($this->enquiryId) ?? false) : null;
    }

    private function matter(): Matter|null|false
    {
        return $this->matterId ? (Matter::query()->visibleTo(auth()->user())->find($this->matterId) ?? false) : null;
    }

    private function contextClient(): ?Client
    {
        return $this->enquiry()?->client ?: $this->matter()?->client ?: null;
    }

    protected function fillForm(): void
    {
        $client = $this->contextClient();
        $this->form->fill([
            'currency' => $client?->preferred_currency ?? 'NGN',
            'title' => $this->enquiry()?->service?->name ?? $this->matter()?->title,
            'lines' => [['kind' => 'fee', 'quantity' => 1]],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $client = $this->contextClient();
        $context = $this->enquiry() ? 'Enquiry '.$this->enquiry()->reference : ($this->matter() ? 'Matter '.$this->matter()->reference : null);

        return $schema->components([
            Section::make($context ? "{$context} – {$client?->display_name}" : 'Client')->columnSpanFull()->schema([
                Grid::make(3)->schema([
                    Select::make('client_id')->label('Client')->required()->searchable()
                        ->visible(! $client)
                        ->getSearchResultsUsing(fn (string $search) => EnquiryResource::clientSearch($search))
                        ->getOptionLabelUsing(fn ($value) => Client::find($value)?->display_name),
                    TextInput::make('title')->required()->maxLength(190)->columnSpan(2),
                    Select::make('currency')->options(Money::currencyOptions())->required()
                        ->helperText('Amounts are in this currency. There is no conversion.'),
                ]),
            ]),
            Section::make('Content')->columnSpanFull()->schema(QuotationResource::contentFields()),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $client = $this->contextClient() ?? Client::query()->visibleTo(auth()->user())->findOrFail($data['client_id']);

        try {
            return app(Quotations::class)->create($client, $this->enquiry() ?: null, $this->matter() ?: null, $data, auth()->user());
        } catch (RuleViolation $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return QuotationResource::getUrl('view', ['record' => $this->record]);
    }
}
