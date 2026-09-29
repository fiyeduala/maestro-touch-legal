<?php

namespace App\Filament\Resources\Payments;

use App\Domain\Billing\Payments;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Billing\PaystackPayments;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Support\DomainActions;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
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
 * Money received, or claimed to be received. A client's transfer slip is only a claim until finance
 * finds the money on the bank statement; an online payment counts only after Paystack verification.
 * Nothing is deleted or edited: mistakes are reversed, and refunds are recorded against the payment.
 */
class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function getNavigationBadge(): ?string
    {
        if (! auth()->user()?->can('create', Payment::class)) {
            return null;
        }
        $count = Payment::whereIn('status', [PaymentStatus::PendingVerification->value, PaymentStatus::NeedsReview->value])->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function infolist(Schema $schema): Schema
    {
        $tz = config('app.firm_timezone');
        $money = fn (Payment $p, ?int $minor) => $minor === null ? null : Money::format($minor, $p->currency);

        return $schema->components([
            Grid::make(3)->columnSpanFull()->schema([
                Section::make('Payment')->columnSpan(2)->schema([
                    TextEntry::make('review_reason')->label('Needs attention')->color('danger')->weight('bold')->visible(fn (Payment $record) => filled($record->review_reason)),
                    Grid::make(2)->schema([
                        TextEntry::make('amount_minor')->label(fn (Payment $record) => $record->method === 'paystack' ? 'Amount initialised' : 'Amount claimed / recorded')
                            ->state(fn (Payment $record) => $money($record, $record->amount_minor)),
                        TextEntry::make('received_minor')->label('Amount received')->placeholder('Not received')
                            ->state(fn (Payment $record) => $record->received_minor === null ? null : Money::format($record->received_minor, $record->received_currency ?: $record->currency)),
                        TextEntry::make('unapplied_minor')->label('Unapplied credit')->state(fn (Payment $record) => $money($record, $record->unapplied_minor)),
                        TextEntry::make('refunded_minor')->label('Refunded')->state(fn (Payment $record) => $money($record, $record->refunded_minor)
                            .($record->refund_pending_minor ? ' (+ '.$money($record, $record->refund_pending_minor).' awaiting Paystack)' : '')),
                        TextEntry::make('bank_reference')->label('Bank reference')->placeholder('—'),
                        TextEntry::make('received_on')->label('Paid / received on')->date()->placeholder('—'),
                        TextEntry::make('provider_reference')->label('Paystack reference')->placeholder('—')->copyable(),
                        TextEntry::make('channel')->placeholder('—'),
                        TextEntry::make('gateway_response')->label('Gateway response')->placeholder('—'),
                        TextEntry::make('dispute_status')->label('Dispute')->placeholder('None')->color('danger'),
                    ]),
                    TextEntry::make('evidence_name')->label('Transfer evidence')->placeholder('None')
                        ->url(fn (Payment $record) => $record->evidence_name && auth()->user()->can('verify', $record) ? route('admin.payment-evidence', $record) : null)
                        ->helperText('A slip or screenshot is not proof. Check the firm\'s bank statement before verifying.'),
                    TextEntry::make('client_note')->label('Client\'s note')->placeholder('—'),
                    TextEntry::make('staff_note')->label('Internal notes')->placeholder('—'),
                    TextEntry::make('rejection_reason')->label('Rejected because')->visible(fn (Payment $record) => filled($record->rejection_reason)),
                ]),
                Section::make('Summary')->columnSpan(1)->schema([
                    TextEntry::make('reference')->copyable(),
                    TextEntry::make('status')->badge()
                        ->formatStateUsing(fn (PaymentStatus $state) => $state->label())
                        ->color(fn (PaymentStatus $state) => $state->color()),
                    TextEntry::make('method')->formatStateUsing(fn (Payment $record) => $record->methodLabel()),
                    TextEntry::make('client.display_name')->label('Client'),
                    TextEntry::make('invoice.reference')->label('Invoice')->placeholder('None (client credit)')
                        ->url(fn (Payment $record) => $record->invoice ? InvoiceResource::getUrl('view', ['record' => $record->invoice]) : null),
                    TextEntry::make('submittedBy.name')->label('Submitted by')->placeholder('—'),
                    TextEntry::make('verifiedBy.name')->label('Verified by')->placeholder('—'),
                    TextEntry::make('verified_at')->label('Verified')->dateTime(timezone: $tz)->placeholder('—'),
                    TextEntry::make('created_at')->label('Created')->dateTime(timezone: $tz),
                ]),
            ]),
            Section::make('Allocations')->columnSpanFull()->visible(fn (Payment $record) => $record->allocations->isNotEmpty())->schema([
                RepeatableEntry::make('allocations')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('created_at')->label('When')->dateTime(timezone: $tz),
                    TextEntry::make('kind')->formatStateUsing(fn ($state) => ucfirst($state)),
                    TextEntry::make('amount_minor')->label('Amount')->formatStateUsing(fn ($state, $record) => Money::format((int) $state, $record->payment->currency)),
                    TextEntry::make('note')->placeholder(''),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['client', 'invoice']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('reference')->searchable(),
                TextColumn::make('client.display_name')->label('Client')->searchable(),
                TextColumn::make('invoice.reference')->label('Invoice')->searchable()->placeholder('Credit'),
                TextColumn::make('method')->formatStateUsing(fn (Payment $record) => $record->methodLabel()),
                TextColumn::make('amount')->state(fn (Payment $record) => Money::format($record->received_minor ?? $record->amount_minor, $record->currency)),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (PaymentStatus $state) => $state->label())
                    ->color(fn (PaymentStatus $state) => $state->color()),
                TextColumn::make('review_reason')->label('Flag')->limit(40)->color('danger')->placeholder(''),
                TextColumn::make('created_at')->label('Created')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(PaymentStatus::options())->multiple(),
                SelectFilter::make('method')->options(Payment::METHODS),
                SelectFilter::make('currency')->options(Money::currencyOptions()),
            ])
            ->recordActions([ViewAction::make()]);
    }

    /** Recording money that reached the account with no invoice yet: it is held as client credit. */
    public static function recordReceivedAction(): Action
    {
        return Action::make('recordReceived')
            ->label('Record money received')
            ->icon(Heroicon::OutlinedBanknotes)
            ->visible(fn () => auth()->user()->can('create', Payment::class))
            ->modalDescription('Money already on the firm\'s bank statement or received in cash. If you choose an invoice it is applied to it; otherwise it is held as client credit.')
            ->schema([
                Grid::make(2)->schema([
                    Select::make('client_id')->label('Client')->required()->searchable()->live()
                        ->getSearchResultsUsing(fn (string $search) => InvoiceResource::billingClientSearch($search))
                        ->getOptionLabelUsing(fn ($value) => Client::find($value)?->display_name),
                    Select::make('currency')->options(Money::currencyOptions())->required()->live(),
                    Select::make('invoice_id')->label('Invoice (optional)')
                        ->options(fn ($get) => $get('client_id') && $get('currency') ? Invoice::where('client_id', $get('client_id'))->where('currency', $get('currency'))->open()
                            ->get()->mapWithKeys(fn (Invoice $i) => [$i->id => "{$i->reference} · balance ".Money::format($i->balanceMinor(), $i->currency)])->all() : []),
                    InvoiceResource::amountField('amount', 'Amount received'),
                    DatePicker::make('received_on')->required()->beforeOrEqual('today')->default(fn () => now(config('app.firm_timezone'))->toDateString()),
                    Select::make('method')->options(['bank_transfer' => 'Bank transfer', 'cash' => 'Cash', 'other' => 'Other'])->required()->default('bank_transfer'),
                    TextInput::make('bank_reference')->maxLength(100)->requiredIf('method', 'bank_transfer'),
                ]),
                Textarea::make('note')->label('Internal note')->maxLength(2000),
            ])
            ->action(function (Action $action, array $data) {
                $client = Client::query()->linkableForBilling(auth()->user())->findOrFail($data['client_id']);
                $invoice = filled($data['invoice_id'] ?? null) ? Invoice::where('client_id', $client->id)->findOrFail($data['invoice_id']) : null;
                DomainActions::run($action, fn () => app(Payments::class)->recordReceived($client, $invoice, [
                    'currency' => $data['currency'], 'amount_minor' => Money::parse($data['amount']), 'method' => $data['method'],
                    'received_on' => $data['received_on'], 'bank_reference' => $data['bank_reference'] ?? null, 'note' => $data['note'] ?? null,
                ], auth()->user()), 'Payment recorded');
            });
    }

    /** @return list<Action|ActionGroup> */
    public static function workActions(): array
    {
        $payments = fn (): Payments => app(Payments::class);
        $isTransferClaim = fn (Payment $record) => $record->status === PaymentStatus::PendingVerification;

        return [
            Action::make('verify')
                ->label('Verify transfer')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->authorize('verify')
                ->visible($isTransferClaim)
                ->modalDescription('Only verify after you have found this money on the firm\'s bank statement. Enter what the statement shows, not what the client typed.')
                ->fillForm(fn (Payment $record) => ['amount' => Money::input($record->amount_minor), 'received_on' => $record->received_on?->toDateString(), 'bank_reference' => $record->bank_reference])
                ->schema(fn (Payment $record) => [
                    Grid::make(2)->schema([
                        InvoiceResource::amountField('amount', "Amount on the statement ({$record->currency})"),
                        DatePicker::make('received_on')->label('Date on the statement')->required()->beforeOrEqual('today'),
                    ]),
                    TextInput::make('bank_reference')->label('Reference on the statement')->required()->maxLength(100),
                    Textarea::make('note')->label('Internal note')->maxLength(2000),
                ])
                ->action(fn (Action $action, Payment $record, array $data) => DomainActions::run($action,
                    fn () => $payments()->verifyTransfer($record, Money::parse($data['amount']), $data['received_on'], $data['bank_reference'], $data['note'] ?? null, auth()->user()), 'Payment verified')),

            Action::make('reject')
                ->label('Not received')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->authorize('verify')
                ->visible($isTransferClaim)
                ->schema([Textarea::make('reason')->label('Reason (shown to the client)')->required()->maxLength(2000)
                    ->placeholder('We could not find this transfer on our statement. Please check with your bank and send the confirmation again.')])
                ->action(fn (Action $action, Payment $record, array $data) => DomainActions::run($action,
                    fn () => $payments()->rejectTransfer($record, $data['reason'], auth()->user()), 'Client told the transfer was not verified')),

            Action::make('resolveReview')
                ->label('Resolve review')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color('warning')
                ->authorize('verify')
                ->visible(fn (Payment $record) => $record->status === PaymentStatus::NeedsReview || ($record->status === PaymentStatus::Succeeded && filled($record->review_reason)))
                ->modalDescription(fn (Payment $record) => $record->review_reason)
                ->schema(fn (Payment $record) => [
                    Radio::make('decision')->required()->options($record->status === PaymentStatus::NeedsReview
                        ? ['accept' => 'Accept the amount received (same currency only)', 'reject' => 'Do not apply it (refund it outside the system or in Paystack)']
                        : ['accept' => 'Checked – clear the flag']),
                    Textarea::make('note')->label('What you checked and decided')->required()->maxLength(2000),
                ])
                ->action(fn (Action $action, Payment $record, array $data) => DomainActions::run($action,
                    fn () => $payments()->resolveReview($record, $data['decision'], $data['note'], auth()->user()), 'Review recorded')),

            Action::make('checkPaystack')
                ->label('Check with Paystack')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->authorize('verify')
                ->visible(fn (Payment $record) => $record->method === 'paystack' && $record->status === PaymentStatus::Pending)
                ->action(function (Action $action, Payment $record) {
                    $outcome = DomainActions::run($action, fn () => app(PaystackPayments::class)->confirm($record, 'reconcile'), 'Checked with Paystack');
                    Notification::make()->info()->title('Result: '.str_replace('_', ' ', (string) $outcome))->send();
                }),

            Action::make('applyCredit')
                ->label('Apply credit to invoice')
                ->icon(Heroicon::OutlinedArrowRightCircle)
                ->authorize('verify')
                ->visible(fn (Payment $record) => $record->availableCreditMinor() > 0)
                ->schema(fn (Payment $record) => [
                    Select::make('invoice_id')->label('Invoice')->required()
                        ->options(Invoice::where('client_id', $record->client_id)->where('currency', $record->currency)->open()->get()
                            ->mapWithKeys(fn (Invoice $i) => [$i->id => "{$i->reference} · balance ".Money::format($i->balanceMinor(), $i->currency)])->all()),
                    InvoiceResource::amountField('amount', "Amount ({$record->currency}, up to ".Money::format($record->availableCreditMinor(), $record->currency).')'),
                ])
                ->action(fn (Action $action, Payment $record, array $data) => DomainActions::run($action,
                    fn () => $payments()->applyCredit($record, Invoice::findOrFail($data['invoice_id']), Money::parse($data['amount']), auth()->user()), 'Credit applied')),

            ActionGroup::make([
                Action::make('refund')
                    ->label(fn (Payment $record) => $record->method === 'paystack' ? 'Refund through Paystack' : 'Record refund made')
                    ->icon(Heroicon::OutlinedReceiptRefund)
                    ->color('warning')
                    ->authorize('verify')
                    ->visible(fn (Payment $record) => $record->status === PaymentStatus::Succeeded)
                    ->modalDescription(fn (Payment $record) => $record->method === 'paystack'
                        ? 'Asks Paystack to refund. It counts as refunded only when Paystack confirms it.'
                        : 'Record a refund you have already paid out of the firm\'s account. Keep the bank reference.')
                    ->schema(fn (Payment $record) => array_filter([
                        InvoiceResource::amountField('amount', "Amount ({$record->currency})"),
                        $record->method === 'paystack' ? null : DatePicker::make('refunded_on')->required()->beforeOrEqual('today'),
                        $record->method === 'paystack' ? null : TextInput::make('bank_reference')->label('Bank reference of the refund')->required()->maxLength(100),
                        Textarea::make('reason')->required()->maxLength(2000),
                    ]))
                    ->action(fn (Action $action, Payment $record, array $data) => DomainActions::run($action,
                        fn () => $record->method === 'paystack'
                            ? app(PaystackPayments::class)->requestRefund($record, Money::parse($data['amount']), $data['reason'], auth()->user())
                            : $payments()->recordManualRefund($record, Money::parse($data['amount']), $data['refunded_on'], $data['bank_reference'], $data['reason'], auth()->user()),
                        $record->method === 'paystack' ? 'Refund requested from Paystack' : 'Refund recorded')),

                Action::make('reverse')
                    ->label('Reverse payment')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('danger')
                    ->authorize('verify')
                    ->visible(fn (Payment $record) => $record->status === PaymentStatus::Succeeded && $record->refunded_minor === 0 && $record->refund_pending_minor === 0)
                    ->modalDescription('For money that should never have counted: a bounced cheque, a transfer recorded in error, a lost chargeback. The invoice becomes unpaid again. Nothing is deleted.')
                    ->schema([Textarea::make('reason')->required()->maxLength(2000)])
                    ->action(fn (Action $action, Payment $record, array $data) => DomainActions::run($action,
                        fn () => $payments()->reverse($record, $data['reason'], auth()->user()), 'Payment reversed')),
            ])->label('More')->icon(Heroicon::OutlinedEllipsisVertical)->button()->color('gray'),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
            'view' => ViewPayment::route('/{record}'),
        ];
    }
}
