<?php

namespace App\Filament\Resources\Quotations;

use App\Domain\Billing\Invoices;
use App\Domain\Engagement\OfferStatus;
use App\Domain\Engagement\Quotations;
use App\Filament\Resources\Enquiries\EnquiryResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Quotations\Pages\CreateQuotation;
use App\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Filament\Resources\Quotations\Pages\ViewQuotation;
use App\Filament\Support\DomainActions;
use App\Models\Invoice;
use App\Models\Quotation;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class QuotationResource extends Resource
{
    protected static ?string $model = Quotation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Practice';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    /** Amounts are typed in major units (e.g. 150000.00) and stored as integer minor units. */
    public static function contentFields(): array
    {
        $amount = fn (string $name, string $label) => TextInput::make($name)->label($label)->required()
            ->regex('/^\d{1,13}(\.\d{1,2})?$/')->validationMessages(['regex' => 'Enter an amount like 150000 or 150000.50 (no commas).']);

        return [
            Textarea::make('scope')->label('Scope of work')->required()->maxLength(10000)->rows(5),
            Textarea::make('exclusions')->label('Not included')->maxLength(5000)->rows(3),
            Repeater::make('lines')->label('Fees and expenses')->required()->minItems(1)->maxItems(50)->columns(6)
                ->defaultItems(1)
                ->schema([
                    Select::make('kind')->options(['fee' => 'Professional fee', 'expense' => 'Expense / disbursement'])->default('fee')->required()->columnSpan(2),
                    TextInput::make('description')->required()->maxLength(300)->columnSpan(2),
                    TextInput::make('quantity')->integer()->minValue(1)->maxValue(10000)->default(1)->required(),
                    $amount('unit', 'Unit amount'),
                ]),
            Repeater::make('payment_stages')->label('Payment stages (optional; must add up to the total)')->maxItems(12)->columns(3)
                ->defaultItems(0)
                ->schema([
                    TextInput::make('label')->required()->maxLength(120),
                    $amount('amount', 'Amount'),
                    TextInput::make('due')->label('When due')->required()->maxLength(120)->placeholder('On signing'),
                ]),
            Grid::make(2)->schema([
                DatePicker::make('valid_until')->label('Valid until')->afterOrEqual('today'),
            ]),
            Textarea::make('notes')->label('Notes to the client')->maxLength(5000)->rows(3),
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        $tz = config('app.firm_timezone');
        $money = fn (Quotation $q, ?int $minor) => $minor === null ? null : Money::format($minor, $q->currency);

        return $schema->components([
            Grid::make(3)->columnSpanFull()->schema([
                Section::make(fn (Quotation $record) => 'Version '.$record->currentVersion?->version)->columnSpan(2)->schema([
                    TextEntry::make('currentVersion.scope')->label('Scope of work'),
                    TextEntry::make('currentVersion.exclusions')->label('Not included')->placeholder('—'),
                    TextEntry::make('lines')->label('Fees and expenses')->listWithLineBreaks()->bulleted()
                        ->state(fn (Quotation $record) => collect($record->currentVersion?->lines ?? [])
                            ->map(fn ($l) => ($l['kind'] === 'expense' ? 'Expense: ' : 'Fee: ')."{$l['description']} – {$l['quantity']} × "
                                .Money::format($l['unit_minor'], $record->currency).' = '.Money::format($l['amount_minor'], $record->currency))->all()),
                    TextEntry::make('currentVersion.payment_stages')->label('Payment stages')->placeholder('Not staged')
                        ->state(fn (Quotation $record) => collect($record->currentVersion?->payment_stages ?? [])
                            ->map(fn ($s) => "{$s['label']}: ".Money::format($s['amount_minor'], $record->currency)." ({$s['due']})")->all() ?: null)
                        ->listWithLineBreaks(),
                    TextEntry::make('currentVersion.notes')->label('Notes to the client')->placeholder('—'),
                ]),
                Section::make('Summary')->columnSpan(1)->schema([
                    TextEntry::make('reference')->copyable(),
                    TextEntry::make('title'),
                    TextEntry::make('status')->badge()
                        ->formatStateUsing(fn (OfferStatus $state) => $state->label())
                        ->color(fn (OfferStatus $state) => $state->color()),
                    TextEntry::make('client.display_name')->label('Client'),
                    TextEntry::make('enquiry.reference')->label('Enquiry')->placeholder('—')
                        ->url(fn (Quotation $record) => $record->enquiry && auth()->user()->can('view', $record->enquiry) ? EnquiryResource::getUrl('view', ['record' => $record->enquiry]) : null),
                    TextEntry::make('matter.reference')->label('Matter')->placeholder('—'),
                    TextEntry::make('fees')->state(fn (Quotation $record) => $money($record, $record->currentVersion?->fees_minor)),
                    TextEntry::make('expenses')->state(fn (Quotation $record) => $money($record, $record->currentVersion?->expenses_minor)),
                    TextEntry::make('total')->weight('bold')->state(fn (Quotation $record) => $money($record, $record->currentVersion?->total_minor)),
                    TextEntry::make('currentVersion.valid_until')->label('Valid until')->date()->placeholder('—'),
                    TextEntry::make('currentVersion.sent_at')->label('Sent')->dateTime(timezone: $tz)->placeholder('Not sent'),
                ]),
            ]),
            Section::make('Client responses')->columnSpanFull()
                ->visible(fn (Quotation $record) => $record->versions->flatMap->acceptances->isNotEmpty())
                ->schema([
                    RepeatableEntry::make('responses')->hiddenLabel()->columns(4)
                        ->state(fn (Quotation $record) => $record->versions->flatMap(fn ($v) => $v->acceptances->map(fn ($a) => [
                            'version' => $v->version, 'decision' => $a->decision, 'by' => $a->user?->name ?? $a->signed_name,
                            'when' => $a->created_at, 'comment' => $a->comment,
                        ]))->values()->all())
                        ->schema([
                            TextEntry::make('version')->prefix('v'),
                            TextEntry::make('decision')->badge(),
                            TextEntry::make('by'),
                            TextEntry::make('when')->dateTime(timezone: $tz),
                            TextEntry::make('comment')->placeholder('')->columnSpanFull(),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['client', 'currentVersion']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('reference')->searchable(),
                TextColumn::make('title')->searchable()->limit(40),
                TextColumn::make('client.display_name')->label('Client')->searchable(),
                TextColumn::make('total')->state(fn (Quotation $record) => $record->currentVersion ? Money::format($record->currentVersion->total_minor, $record->currency) : null),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (OfferStatus $state) => $state->label())
                    ->color(fn (OfferStatus $state) => $state->color()),
                TextColumn::make('updated_at')->label('Updated')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(OfferStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all())->multiple(),
            ])
            ->recordActions([ViewAction::make()]);
    }

    /** @return list<Action> */
    public static function workActions(): array
    {
        $service = fn (): Quotations => app(Quotations::class);

        return [
            Action::make('send')
                ->label('Send to client')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('primary')
                ->authorize('update')
                ->visible(fn (Quotation $record) => $record->status === OfferStatus::Draft)
                ->requiresConfirmation()
                ->modalDescription('Freezes this version and notifies the client\'s portal contacts. Later changes create a new version.')
                ->action(fn (Action $action, Quotation $record) => DomainActions::run($action,
                    fn () => $service()->send($record, auth()->user()), 'Quotation sent')),

            Action::make('revise')
                ->label(fn (Quotation $record) => $record->status === OfferStatus::Draft ? 'Edit draft' : 'Revise (new version)')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->authorize('update')
                ->modalWidth('5xl')
                ->fillForm(fn (Quotation $record) => ['title' => $record->title, ...Quotations::toForm($record->currentVersion)])
                ->schema([TextInput::make('title')->required()->maxLength(190), ...self::contentFields()])
                ->action(fn (Action $action, Quotation $record, array $data) => DomainActions::run($action,
                    fn () => $service()->revise($record, $data, auth()->user()), 'Quotation saved')),

            Action::make('withdraw')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->authorize('update')
                ->visible(fn (Quotation $record) => $record->status === OfferStatus::Sent)
                ->schema([Textarea::make('reason')->label('Reason (internal)')->required()->maxLength(2000)])
                ->action(fn (Action $action, Quotation $record, array $data) => DomainActions::run($action,
                    fn () => $service()->withdraw($record, $data['reason'], auth()->user()), 'Quotation withdrawn')),

            Action::make('invoice')
                ->label('Draft invoice')
                ->icon(Heroicon::OutlinedDocumentCurrencyDollar)
                ->visible(fn (Quotation $record) => $record->status === OfferStatus::Accepted && auth()->user()->can('create', Invoice::class))
                ->modalDescription('Creates a draft invoice from the accepted quotation, in the same currency. You can edit the draft before issuing it.')
                ->schema(fn (Quotation $record) => [
                    Select::make('stage')->label('Invoice for')->required()->default('all')
                        ->options(['all' => 'The whole quotation'] + collect($record->loadMissing('acceptedVersion')->acceptedVersion?->payment_stages ?? [])
                            ->mapWithKeys(fn (array $s, int $i) => [(string) $i => ($s['label'] ?: 'Stage '.($i + 1)).' · '.Money::format((int) $s['amount_minor'], $record->currency)])->all()),
                ])
                ->action(function (Action $action, Quotation $record, array $data) {
                    $invoice = DomainActions::run($action, fn () => app(Invoices::class)
                        ->draftFromQuotation($record, $data['stage'] === 'all' ? null : (int) $data['stage'], auth()->user()), 'Draft invoice created');
                    $action->redirect(InvoiceResource::getUrl('view', ['record' => $invoice]));
                }),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuotations::route('/'),
            'create' => CreateQuotation::route('/create'),
            'view' => ViewQuotation::route('/{record}'),
        ];
    }
}
