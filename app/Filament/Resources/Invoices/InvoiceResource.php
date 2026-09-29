<?php

namespace App\Filament\Resources\Invoices;

use App\Domain\Billing\Invoices;
use App\Domain\Billing\InvoiceStatus;
use App\Domain\Billing\Payments;
use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Support\DomainActions;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Matter;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Invoices are drafted, then issued (frozen). Issued invoices are never edited: corrections are credit
 * notes, and wrong payments are reversed. Every amount is in the invoice's one currency.
 */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    /** Client search for billing forms: finance officers find any client, others only clients they can see. */
    public static function billingClientSearch(string $search): array
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

        return Client::query()->linkableForBilling(auth()->user())
            ->where(fn (Builder $query) => $query->where('display_name', 'like', $like)->orWhere('reference', $search))
            ->orderBy('display_name')->limit(20)->get()
            ->mapWithKeys(fn (Client $c) => [$c->id => "{$c->display_name} ({$c->reference})"])->all();
    }

    public static function amountField(string $name, string $label): TextInput
    {
        return TextInput::make($name)->label($label)->required()
            ->regex('/^\d{1,13}(\.\d{1,2})?$/')->validationMessages(['regex' => 'Enter an amount like 150000 or 150000.50 (no commas).']);
    }

    /** Draft content. Expenses are picked from the client's unbilled expenses in the same currency. */
    public static function contentFields(callable $clientId, callable $currency, ?callable $invoiceId = null): array
    {
        return [
            Grid::make(3)->schema([
                TextInput::make('title')->required()->maxLength(190)->columnSpan(2),
                DatePicker::make('due_date')->label('Due date')->afterOrEqual('today'),
                Select::make('matter_id')->label('Matter (optional)')->columnSpan(2)
                    ->options(fn () => $clientId() ? Matter::query()->linkableForBilling(auth()->user())->where('client_id', $clientId())
                        ->orderByDesc('opened_at')->get()->mapWithKeys(fn (Matter $m) => [$m->id => "{$m->reference} – {$m->title}"])->all() : []),
            ]),
            Repeater::make('lines')->label('Fees and other charges')->maxItems(50)->columns(6)->defaultItems(0)
                ->schema([
                    Select::make('kind')->options(['fee' => 'Professional fee', 'expense' => 'Expense / disbursement', 'tax' => 'Tax'])->default('fee')->required()->columnSpan(2),
                    TextInput::make('description')->required()->maxLength(300)->columnSpan(2),
                    TextInput::make('quantity')->integer()->minValue(1)->maxValue(10000)->default(1)->required(),
                    self::amountField('unit', 'Unit amount'),
                ]),
            Select::make('expense_ids')->label('Recorded expenses to bill')->multiple()
                ->helperText('Only billable, unbilled expenses for this client in the invoice currency.')
                ->options(fn () => $clientId() && $currency() ? Invoices::billableExpenseOptions($clientId(), $currency(), $invoiceId ? $invoiceId() : null) : []),
            Textarea::make('notes')->label('Notes to the client')->maxLength(5000)->rows(3),
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        $tz = config('app.firm_timezone');
        $money = fn (Invoice $i, ?int $minor) => $minor === null ? null : Money::format($minor, $i->currency);

        return $schema->components([
            Grid::make(3)->columnSpanFull()->schema([
                Section::make('Lines')->columnSpan(2)->schema([
                    RepeatableEntry::make('lines')->hiddenLabel()->columns(4)->schema([
                        TextEntry::make('description')->columnSpan(2),
                        TextEntry::make('quantity')->formatStateUsing(fn ($state, $record) => $state.' × '.Money::format($record->unit_minor, $record->invoice->currency)),
                        TextEntry::make('amount_minor')->label('Amount')->formatStateUsing(fn ($state, $record) => Money::format((int) $state, $record->invoice->currency)),
                    ]),
                    TextEntry::make('notes')->label('Notes to the client')->placeholder('—'),
                ]),
                Section::make('Summary')->columnSpan(1)->schema([
                    TextEntry::make('reference')->copyable(),
                    TextEntry::make('status')->badge()
                        ->formatStateUsing(fn (InvoiceStatus $state) => $state->label())
                        ->color(fn (InvoiceStatus $state) => $state->color()),
                    TextEntry::make('client.display_name')->label('Client'),
                    TextEntry::make('matter.reference')->label('Matter')->placeholder('—'),
                    TextEntry::make('quotation.reference')->label('Quotation')->placeholder('—'),
                    TextEntry::make('total_minor')->label('Total')->weight('bold')->state(fn (Invoice $record) => $money($record, $record->total_minor)),
                    TextEntry::make('credited_minor')->label('Credited')->state(fn (Invoice $record) => $money($record, $record->credited_minor)),
                    TextEntry::make('paid_minor')->label('Paid')->state(fn (Invoice $record) => $money($record, $record->paid_minor)),
                    TextEntry::make('balance')->weight('bold')->state(fn (Invoice $record) => $money($record, $record->balanceMinor()))
                        ->color(fn (Invoice $record) => $record->isOverdue() ? 'danger' : null),
                    TextEntry::make('issue_date')->label('Issued')->date()->placeholder('Not issued'),
                    TextEntry::make('due_date')->label('Due')->date()->placeholder('—'),
                    TextEntry::make('issuedBy.name')->label('Issued by')->placeholder('—'),
                    TextEntry::make('cancel_reason')->label('Discarded because')->visible(fn (Invoice $record) => $record->status === InvoiceStatus::Cancelled),
                ]),
            ]),
            Section::make('Payments and adjustments')->columnSpanFull()
                ->visible(fn (Invoice $record) => $record->allocations->isNotEmpty() || $record->creditNotes->isNotEmpty())
                ->schema([
                    RepeatableEntry::make('ledger')->hiddenLabel()->columns(4)
                        ->state(fn (Invoice $record) => $record->allocations->map(fn ($a) => [
                            'when' => $a->created_at, 'what' => ucfirst($a->kind).($a->payment ? ' · '.$a->payment->reference : ''),
                            'amount' => Money::format($a->amount_minor, $record->currency), 'note' => $a->note,
                        ])->concat($record->creditNotes->map(fn ($c) => [
                            'when' => $c->created_at, 'what' => 'Credit note '.$c->reference, 'amount' => Money::format($c->amount_minor, $record->currency), 'note' => $c->reason,
                        ]))->sortBy('when')->values()->all())
                        ->schema([
                            TextEntry::make('when')->dateTime(timezone: $tz),
                            TextEntry::make('what'),
                            TextEntry::make('amount'),
                            TextEntry::make('note')->placeholder(''),
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
                TextColumn::make('title')->searchable()->limit(40),
                TextColumn::make('client.display_name')->label('Client')->searchable(),
                TextColumn::make('total_minor')->label('Total')->state(fn (Invoice $record) => Money::format($record->total_minor, $record->currency)),
                TextColumn::make('balance')->state(fn (Invoice $record) => Money::format($record->balanceMinor(), $record->currency))
                    ->color(fn (Invoice $record) => $record->isOverdue() ? 'danger' : null),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (InvoiceStatus $state) => $state->label())
                    ->color(fn (InvoiceStatus $state) => $state->color()),
                TextColumn::make('due_date')->label('Due')->date()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(InvoiceStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all())->multiple(),
                SelectFilter::make('currency')->options(Money::currencyOptions()),
                Filter::make('overdue')->query(fn (Builder $query) => $query->open()->whereDate('due_date', '<', now(config('app.firm_timezone'))->toDateString())),
            ])
            ->recordActions([ViewAction::make()]);
    }

    /** @return list<Action> */
    public static function workActions(): array
    {
        $invoices = fn (): Invoices => app(Invoices::class);

        return [
            Action::make('issue')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('primary')
                ->authorize('update')
                ->visible(fn (Invoice $record) => $record->status === InvoiceStatus::Draft)
                ->requiresConfirmation()
                ->modalDescription('Freezes the invoice and notifies the client\'s portal contacts. After this, corrections are made with credit notes.')
                ->action(fn (Action $action, Invoice $record) => DomainActions::run($action,
                    fn () => $invoices()->issue($record, auth()->user()), 'Invoice issued')),

            Action::make('editDraft')
                ->label('Edit draft')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->authorize('update')
                ->visible(fn (Invoice $record) => $record->status === InvoiceStatus::Draft)
                ->modalWidth('5xl')
                ->fillForm(fn (Invoice $record) => Invoices::toForm($record))
                ->schema(fn (Invoice $record) => self::contentFields(fn () => $record->client_id, fn () => $record->currency, fn () => $record->id))
                ->action(fn (Action $action, Invoice $record, array $data) => DomainActions::run($action,
                    fn () => $invoices()->updateDraft($record, $data, auth()->user()), 'Draft saved')),

            Action::make('discard')
                ->label('Discard draft')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->authorize('update')
                ->visible(fn (Invoice $record) => $record->status === InvoiceStatus::Draft)
                ->schema([Textarea::make('reason')->label('Reason (internal)')->required()->maxLength(2000)])
                ->action(fn (Action $action, Invoice $record, array $data) => DomainActions::run($action,
                    fn () => $invoices()->discardDraft($record, $data['reason'], auth()->user()), 'Draft discarded')),

            Action::make('recordPayment')
                ->label('Record payment received')
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('success')
                ->authorize('manage')
                ->visible(fn (Invoice $record) => $record->status->isOpen())
                ->modalDescription('Only for money that has reached the firm\'s account (bank transfer, cash, cheque). For a client\'s uploaded slip, verify it under Payments instead.')
                ->fillForm(fn (Invoice $record) => ['amount' => Money::toDecimal($record->balanceMinor()), 'received_on' => now(config('app.firm_timezone'))->toDateString(), 'method' => 'bank_transfer'])
                ->schema(fn (Invoice $record) => [
                    Grid::make(2)->schema([
                        self::amountField('amount', "Amount received ({$record->currency})"),
                        DatePicker::make('received_on')->required()->beforeOrEqual('today'),
                        Select::make('method')->options(['bank_transfer' => 'Bank transfer', 'cash' => 'Cash', 'other' => 'Other'])->required(),
                        TextInput::make('bank_reference')->maxLength(100)->requiredIf('method', 'bank_transfer'),
                    ]),
                    Textarea::make('note')->label('Internal note')->maxLength(2000),
                ])
                ->action(fn (Action $action, Invoice $record, array $data) => DomainActions::run($action,
                    fn () => app(Payments::class)->recordReceived($record->client, $record, [
                        'currency' => $record->currency, 'amount_minor' => Money::parse($data['amount']), 'method' => $data['method'],
                        'received_on' => $data['received_on'], 'bank_reference' => $data['bank_reference'] ?? null, 'note' => $data['note'] ?? null,
                    ], auth()->user()), 'Payment recorded')),

            Action::make('credit')
                ->label('Credit note')
                ->icon(Heroicon::OutlinedReceiptRefund)
                ->color('warning')
                ->authorize('manage')
                ->visible(fn (Invoice $record) => $record->status->isOpen())
                ->modalDescription('Reduces what the client owes without changing the issued invoice. It cannot exceed the unpaid balance; to return money already paid, refund the payment.')
                ->schema(fn (Invoice $record) => [
                    self::amountField('amount', "Amount ({$record->currency})"),
                    Textarea::make('reason')->label('Reason (shown to the client)')->required()->maxLength(2000),
                ])
                ->action(fn (Action $action, Invoice $record, array $data) => DomainActions::run($action,
                    fn () => $invoices()->credit($record, Money::parse($data['amount']), $data['reason'], auth()->user()), 'Credit note issued')),

        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'create' => CreateInvoice::route('/create'),
            'view' => ViewInvoice::route('/{record}'),
        ];
    }
}
