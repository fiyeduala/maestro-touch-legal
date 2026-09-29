<?php

namespace App\Filament\Resources\Expenses;

use App\Domain\Billing\Expenses;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Support\DomainActions;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Matter;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Disbursements paid on a client's behalf (filing fees, courier, search fees). Billable ones can be
 * added to that client's draft invoice in the same currency. Recorded expenses are voided, never edited.
 */
class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 30;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['client', 'matter', 'invoice']))
            ->defaultSort('incurred_on', 'desc')
            ->columns([
                TextColumn::make('reference')->searchable(),
                TextColumn::make('incurred_on')->label('Date')->date()->sortable(),
                TextColumn::make('client.display_name')->label('Client')->searchable(),
                TextColumn::make('matter.reference')->label('Matter')->placeholder('—'),
                TextColumn::make('description')->limit(50)->searchable()
                    ->description(fn (Expense $record) => $record->voided_at ? 'Voided: '.$record->void_reason : null),
                TextColumn::make('amount')->state(fn (Expense $record) => Money::format($record->amount_minor, $record->currency)),
                IconColumn::make('billable')->boolean(),
                TextColumn::make('invoice.reference')->label('On invoice')->placeholder('Not billed')
                    ->url(fn (Expense $record) => $record->invoice && auth()->user()->can('view', $record->invoice) ? InvoiceResource::getUrl('view', ['record' => $record->invoice]) : null),
            ])
            ->filters([
                SelectFilter::make('currency')->options(Money::currencyOptions()),
                TernaryFilter::make('unbilled')->label('Billing')
                    ->trueLabel('Billable, not yet invoiced')->falseLabel('Invoiced or not billable')
                    ->queries(
                        true: fn (Builder $q) => $q->unbilled(),
                        false: fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('billable', false)->orWhereNotNull('invoice_id')),
                    ),
                TernaryFilter::make('voided')->nullable()->attribute('voided_at')->label('Voided'),
            ])
            ->recordActions([
                Action::make('void')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->authorize('void')
                    ->visible(fn (Expense $record) => $record->voided_at === null)
                    ->modalDescription('The expense stays on record, marked void. An expense already on an issued invoice must be corrected with a credit note.')
                    ->schema([Textarea::make('reason')->required()->maxLength(2000)])
                    ->action(fn (Action $action, Expense $record, array $data) => DomainActions::run($action,
                        fn () => app(Expenses::class)->void($record, $data['reason'], auth()->user()), 'Expense voided')),
            ]);
    }

    public static function recordAction(): Action
    {
        return Action::make('record')
            ->label('Record expense')
            ->icon(Heroicon::OutlinedPlus)
            ->visible(fn () => auth()->user()->can('create', Expense::class))
            ->fillForm(fn () => ['currency' => 'NGN', 'billable' => true, 'incurred_on' => now(config('app.firm_timezone'))->toDateString()])
            ->schema([
                Grid::make(2)->schema([
                    Select::make('client_id')->label('Client')->required()->searchable()->live()
                        ->getSearchResultsUsing(fn (string $search) => InvoiceResource::billingClientSearch($search))
                        ->getOptionLabelUsing(fn ($value) => Client::find($value)?->display_name),
                    Select::make('matter_id')->label('Matter (optional)')
                        ->options(fn ($get) => self::matterOptions($get('client_id'))),
                    Select::make('currency')->options(Money::currencyOptions())->required(),
                    InvoiceResource::amountField('amount', 'Amount'),
                    DatePicker::make('incurred_on')->label('Date paid')->required()->beforeOrEqual('today'),
                    Toggle::make('billable')->label('Recharge to the client')->inline(false),
                ]),
                TextInput::make('description')->required()->maxLength(300)->placeholder('e.g. CAC search fee'),
            ])
            ->action(function (Action $action, array $data) {
                $client = Client::query()->linkableForBilling(auth()->user())->findOrFail($data['client_id']);
                $matter = filled($data['matter_id'] ?? null) ? Matter::query()->linkableForBilling(auth()->user())->where('client_id', $client->id)->findOrFail($data['matter_id']) : null;
                DomainActions::run($action, fn () => app(Expenses::class)->record($client, $matter, [
                    'currency' => $data['currency'], 'amount_minor' => Money::parse($data['amount']), 'description' => $data['description'],
                    'incurred_on' => $data['incurred_on'], 'billable' => (bool) $data['billable'],
                ], auth()->user()), 'Expense recorded');
            });
    }

    /** @return array<int, string> */
    public static function matterOptions(mixed $clientId): array
    {
        if (! filled($clientId)) {
            return [];
        }

        return Matter::query()->linkableForBilling(auth()->user())->where('client_id', $clientId)->orderByDesc('id')
            ->get()->mapWithKeys(fn (Matter $m) => [$m->id => "{$m->reference} · {$m->title}"])->all();
    }

    public static function getPages(): array
    {
        return ['index' => ListExpenses::route('/')];
    }
}
