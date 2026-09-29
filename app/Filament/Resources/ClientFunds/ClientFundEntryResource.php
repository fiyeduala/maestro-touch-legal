<?php

namespace App\Filament\Resources\ClientFunds;

use App\Domain\Billing\ClientFunds;
use App\Domain\Documents\UploadGuard;
use App\Filament\Resources\ClientFunds\Pages\ListClientFundEntries;
use App\Filament\Resources\ClientFunds\Pages\ViewClientFundEntry;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\DomainActions;
use App\Models\Client;
use App\Models\ClientFundEntry;
use App\Models\Matter;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Money held for clients (for example settlement sums), kept apart from fees. The ledger is
 * append-only: a wrong entry is reversed by a new entry. Nothing is deducted or paid out automatically;
 * every outflow needs a recorded authorisation, and no balance may go below zero.
 */
class ClientFundEntryResource extends Resource
{
    protected static ?string $model = ClientFundEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?string $navigationLabel = 'Client funds';

    protected static ?string $slug = 'client-funds';

    protected static ?string $modelLabel = 'client-funds entry';

    protected static ?string $pluralModelLabel = 'client funds';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function infolist(Schema $schema): Schema
    {
        $tz = config('app.firm_timezone');

        return $schema->components([
            Grid::make(3)->columnSpanFull()->schema([
                Section::make('Entry')->columnSpan(2)->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('type')->formatStateUsing(fn (ClientFundEntry $record) => $record->typeLabel()),
                        TextEntry::make('amount_minor')->label('Amount')
                            ->state(fn (ClientFundEntry $record) => ($record->amount_minor < 0 ? 'Out ' : 'In ').Money::format(abs($record->amount_minor), $record->currency)),
                        TextEntry::make('occurred_on')->label('Date')->date(),
                        TextEntry::make('bank_reference')->label('Bank reference')->placeholder('—'),
                        TextEntry::make('counterparty')->placeholder('—'),
                    ]),
                    TextEntry::make('description'),
                    TextEntry::make('authorisation')->placeholder('—'),
                    TextEntry::make('evidence_name')->label('Evidence')->placeholder('None')
                        ->url(fn (ClientFundEntry $record) => $record->evidence_name ? route('admin.fund-evidence', $record) : null),
                    TextEntry::make('reverses.reference')->label('Reverses')->visible(fn (ClientFundEntry $record) => $record->reverses_entry_id !== null)
                        ->url(fn (ClientFundEntry $record) => $record->reverses ? self::getUrl('view', ['record' => $record->reverses]) : null),
                    TextEntry::make('reversedBy.reference')->label('Reversed by')->visible(fn (ClientFundEntry $record) => $record->reversedBy !== null)
                        ->url(fn (ClientFundEntry $record) => $record->reversedBy ? self::getUrl('view', ['record' => $record->reversedBy]) : null),
                ]),
                Section::make('Summary')->columnSpan(1)->schema([
                    TextEntry::make('reference')->copyable(),
                    TextEntry::make('client.display_name')->label('Client'),
                    TextEntry::make('matter.reference')->label('Matter')->placeholder('—'),
                    TextEntry::make('balance')->label(fn (ClientFundEntry $record) => "Client's {$record->currency} balance now")
                        ->state(fn (ClientFundEntry $record) => Money::format(app(ClientFunds::class)->balance($record->client_id, $record->currency), $record->currency)),
                    TextEntry::make('createdBy.name')->label('Recorded by')->placeholder('—'),
                    TextEntry::make('created_at')->label('Recorded')->dateTime(timezone: $tz),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['client', 'matter']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('reference')->searchable(),
                TextColumn::make('occurred_on')->label('Date')->date()->sortable(),
                TextColumn::make('client.display_name')->label('Client')->searchable(),
                TextColumn::make('matter.reference')->label('Matter')->placeholder('—'),
                TextColumn::make('type')->formatStateUsing(fn (ClientFundEntry $record) => $record->typeLabel()),
                TextColumn::make('description')->limit(50)->searchable(),
                TextColumn::make('in')->label('In')->alignEnd()
                    ->state(fn (ClientFundEntry $record) => $record->amount_minor > 0 ? Money::format($record->amount_minor, $record->currency) : null),
                TextColumn::make('out')->label('Out')->alignEnd()
                    ->state(fn (ClientFundEntry $record) => $record->amount_minor < 0 ? Money::format(-$record->amount_minor, $record->currency) : null),
            ])
            ->filters([
                SelectFilter::make('currency')->options(Money::currencyOptions()),
                SelectFilter::make('type')->options(ClientFundEntry::TYPES),
                SelectFilter::make('client_id')->label('Client')->searchable()
                    ->getSearchResultsUsing(fn (string $search) => InvoiceResource::billingClientSearch($search))
                    ->getOptionLabelUsing(fn ($value) => Client::find($value)?->display_name),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function recordAction(): Action
    {
        return Action::make('record')
            ->label('Record movement')
            ->icon(Heroicon::OutlinedPlus)
            ->visible(fn () => auth()->user()->can('create', ClientFundEntry::class))
            ->modalDescription('Only money actually received into, or paid from, the client-funds account. Fee deductions and payments out need the client\'s recorded authorisation.')
            ->fillForm(fn () => ['currency' => 'NGN', 'type' => 'receipt', 'occurred_on' => now(config('app.firm_timezone'))->toDateString()])
            ->schema([
                Grid::make(2)->schema([
                    Select::make('client_id')->label('Client')->required()->searchable()->live()
                        ->getSearchResultsUsing(fn (string $search) => InvoiceResource::billingClientSearch($search))
                        ->getOptionLabelUsing(fn ($value) => Client::find($value)?->display_name),
                    Select::make('matter_id')->label('Matter (optional)')
                        ->options(fn ($get) => ExpenseResource::matterOptions($get('client_id'))),
                    Select::make('type')->options(collect(ClientFundEntry::TYPES)->except('reversal')->all())->required()->live(),
                    Select::make('currency')->options(Money::currencyOptions())->required(),
                    InvoiceResource::amountField('amount', 'Amount'),
                    DatePicker::make('occurred_on')->label('Date')->required()->beforeOrEqual('today'),
                    TextInput::make('counterparty')->label(fn ($get) => $get('type') === 'receipt' ? 'Received from' : 'Paid to')->maxLength(255),
                    TextInput::make('bank_reference')->maxLength(100),
                ]),
                TextInput::make('description')->required()->maxLength(500),
                Textarea::make('authorisation')->maxLength(2000)
                    ->required(fn ($get) => $get('type') !== 'receipt')
                    ->visible(fn ($get) => $get('type') !== 'receipt')
                    ->helperText('Who authorised this, when and how (for example "Client email of 2 Oct 2026 approving the 10% fee").'),
                FileUpload::make('evidence')->label('Evidence (optional)')->storeFiles(false)
                    ->acceptedFileTypes(array_values(array_unique(UploadGuard::TYPES)))->maxSize(UploadGuard::MAX_KILOBYTES)
                    ->helperText('Bank advice, authorisation letter or receipt. Stored privately. Files are not virus-scanned.'),
            ])
            ->action(function (Action $action, array $data) {
                $client = Client::query()->linkableForBilling(auth()->user())->findOrFail($data['client_id']);
                $matter = filled($data['matter_id'] ?? null) ? Matter::query()->linkableForBilling(auth()->user())->where('client_id', $client->id)->findOrFail($data['matter_id']) : null;
                DomainActions::run($action, fn () => app(ClientFunds::class)->record($client, $matter, [
                    'currency' => $data['currency'], 'type' => $data['type'], 'amount_minor' => Money::parse($data['amount']),
                    'occurred_on' => $data['occurred_on'], 'description' => $data['description'], 'counterparty' => $data['counterparty'] ?? null,
                    'bank_reference' => $data['bank_reference'] ?? null, 'authorisation' => $data['authorisation'] ?? null,
                ], $data['evidence'] ?? null, auth()->user()), 'Entry recorded');
            });
    }

    /** Compares the client-funds bank account with the ledger total for one currency and records the result. */
    public static function reconcileAction(): Action
    {
        return Action::make('reconcile')
            ->label('Reconcile bank account')
            ->icon(Heroicon::OutlinedScale)
            ->color('gray')
            ->visible(fn () => auth()->user()->can('create', ClientFundEntry::class))
            ->modalDescription('Enter the closing balance of the client-funds bank account from its statement. The system compares it with the total of all client ledgers in that currency on that date and records the difference. It changes no entries.')
            ->fillForm(fn () => ['currency' => 'NGN', 'statement_date' => now(config('app.firm_timezone'))->toDateString()])
            ->schema([
                Grid::make(3)->schema([
                    Select::make('currency')->options(Money::currencyOptions())->required(),
                    DatePicker::make('statement_date')->required()->beforeOrEqual('today'),
                    TextInput::make('balance')->label('Statement balance')->required()
                        ->regex('/^-?\d{1,13}(\.\d{1,2})?$/')->validationMessages(['regex' => 'Enter an amount like 150000 or 150000.50 (no commas).']),
                ]),
                Textarea::make('note')->maxLength(2000),
            ])
            ->action(function (Action $action, array $data) {
                $negative = str_starts_with($data['balance'], '-');
                $minor = Money::parse(ltrim($data['balance'], '-')) * ($negative ? -1 : 1);
                $record = DomainActions::run($action, fn () => app(ClientFunds::class)
                    ->reconcile($data['currency'], $data['statement_date'], $minor, $data['note'] ?? null, auth()->user()), 'Reconciliation recorded');
                $difference = $record->differenceMinor();
                if ($difference !== 0) {
                    Notification::make()->danger()->persistent()->title('The balances do not agree')
                        ->body('Statement minus ledger: '.Money::format($difference, $record->currency).'. Investigate before relying on the ledger.')->send();
                }
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClientFundEntries::route('/'),
            'view' => ViewClientFundEntry::route('/{record}'),
        ];
    }
}
