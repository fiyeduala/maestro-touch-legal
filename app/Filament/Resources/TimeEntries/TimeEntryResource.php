<?php

namespace App\Filament\Resources\TimeEntries;

use App\Domain\Billing\TimeEntries;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\TimeEntries\Pages\ListTimeEntries;
use App\Filament\Support\DomainActions;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * Time recorded on matters (D53). Staff record their own time by hand or with a timer; finance bills
 * unbilled time on a draft invoice. The matter's Time tab shows the same entries for one matter.
 */
class TimeEntryResource extends Resource
{
    protected static ?string $model = TimeEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Practice';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Time';

    protected static ?string $modelLabel = 'time entry';

    protected static ?string $pluralModelLabel = 'Time';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();

        return $user && TimeEntry::running()->where('user_id', $user->id)->exists() ? 'Timer on' : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'matter', 'client', 'invoice']))
            ->defaultSort('worked_on', 'desc')
            ->columns([
                TextColumn::make('worked_on')->label('Date')->date('j M Y')->sortable(),
                TextColumn::make('user.name')->label('Who')->searchable(),
                TextColumn::make('matter.reference')->label('Matter')->searchable()
                    ->description(fn (TimeEntry $record) => $record->client?->display_name),
                ...self::entryColumns(),
            ])
            ->filters([
                SelectFilter::make('user_id')->label('Staff member')->searchable()
                    ->options(fn () => User::query()->whereIn('id', TimeEntry::query()->visibleTo(auth()->user())->select('user_id'))
                        ->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('status')->label('Billing')
                    ->options(['unbilled' => 'Not billed', 'invoiced' => 'On an invoice', 'not_billable' => 'Not billable', 'running' => 'Timer running', 'voided' => 'Voided'])
                    ->query(fn (Builder $q, array $data) => match ($data['value'] ?? null) {
                        'unbilled' => $q->unbilled(),
                        'invoiced' => $q->whereNotNull('invoice_id'),
                        'not_billable' => $q->where('billable', false)->whereNull('voided_at'),
                        'running' => $q->running(),
                        'voided' => $q->whereNotNull('voided_at'),
                        default => $q,
                    }),
                Filter::make('dates')
                    ->schema([DatePicker::make('from'), DatePicker::make('until')])->columns(2)
                    ->query(fn (Builder $q, array $data) => $q
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('worked_on', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('worked_on', '<=', $d))),
            ])
            ->recordActions(self::entryActions())
            ->toolbarActions([self::draftInvoiceAction()]);
    }

    /** Columns shared with the matter's Time tab. */
    public static function entryColumns(): array
    {
        $tz = config('app.firm_timezone');

        return [
            TextColumn::make('minutes')->label('Time')
                ->state(fn (TimeEntry $record) => $record->isRunning() ? 'Since '.$record->started_at->timezone($tz)->format('H:i') : TimeEntry::duration($record->minutes)),
            TextColumn::make('description')->wrap()->limit(80)->searchable()->placeholder('—')
                ->description(fn (TimeEntry $record) => $record->voided_at ? 'Voided: '.$record->void_reason : null),
            TextColumn::make('status')->badge()
                ->state(fn (TimeEntry $record) => $record->statusLabel())
                ->color(fn (TimeEntry $record) => $record->statusColor())
                ->description(fn (TimeEntry $record) => $record->billed_minor !== null && $record->invoice
                    ? Money::format($record->billed_minor, $record->invoice->currency) : null)
                ->url(fn (TimeEntry $record) => $record->invoice && auth()->user()->can('view', $record->invoice) ? InvoiceResource::getUrl('view', ['record' => $record->invoice]) : null),
        ];
    }

    /** Entry fields for recording and correcting time. Hours and minutes are entered separately. */
    public static function entryFields(): array
    {
        return [
            Grid::make(3)->schema([
                DatePicker::make('worked_on')->label('Date')->required()->beforeOrEqual('today'),
                TextInput::make('hours')->integer()->minValue(0)->maxValue(24)->default(0)->required(),
                TextInput::make('mins')->label('Minutes')->integer()->minValue(0)->maxValue(59)->default(0)->required(),
            ]),
            TextInput::make('description')->label('Work done')->required()->maxLength(300)->placeholder('e.g. Reviewed draft lease and sent comments'),
            Toggle::make('billable')->label('Billable to the client')->default(true),
        ];
    }

    /** @return array{worked_on: string, minutes: int, description: string, billable: bool} */
    public static function entryData(array $data): array
    {
        return [
            'worked_on' => (string) $data['worked_on'],
            'minutes' => (int) $data['hours'] * 60 + (int) $data['mins'],
            'description' => (string) $data['description'],
            'billable' => (bool) ($data['billable'] ?? true),
        ];
    }

    public static function entryActions(): array
    {
        return [
            Action::make('stop')
                ->label('Stop')
                ->icon(Heroicon::OutlinedStop)
                ->color('warning')
                ->authorize('stop')
                ->schema([
                    TextInput::make('description')->label('Work done')->required()->maxLength(300),
                    Toggle::make('billable')->label('Billable to the client')->default(true),
                ])
                ->action(fn (Action $action, TimeEntry $record, array $data) => DomainActions::run($action,
                    fn () => app(TimeEntries::class)->stopTimer($record, $data['description'], (bool) $data['billable'], auth()->user()), 'Time recorded')),
            ActionGroup::make([
                Action::make('discard')
                    ->label('Discard timer')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('gray')
                    ->authorize('stop')
                    ->requiresConfirmation()
                    ->modalDescription('The running timer is removed and no time is recorded.')
                    ->action(fn (Action $action, TimeEntry $record) => DomainActions::run($action,
                        fn () => app(TimeEntries::class)->discardTimer($record, auth()->user()), 'Timer discarded')),
                Action::make('edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->authorize('update')
                    ->fillForm(fn (TimeEntry $record) => [
                        'worked_on' => $record->worked_on->toDateString(), 'hours' => intdiv($record->minutes, 60), 'mins' => $record->minutes % 60,
                        'description' => $record->description, 'billable' => $record->billable,
                    ])
                    ->schema(self::entryFields())
                    ->action(fn (Action $action, TimeEntry $record, array $data) => DomainActions::run($action,
                        fn () => app(TimeEntries::class)->update($record, self::entryData($data), auth()->user()), 'Time entry saved')),
                Action::make('void')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->authorize('void')
                    ->modalDescription('The entry stays on record, marked void, and is never billed.')
                    ->schema([Textarea::make('reason')->required()->minLength(5)->maxLength(2000)])
                    ->action(fn (Action $action, TimeEntry $record, array $data) => DomainActions::run($action,
                        fn () => app(TimeEntries::class)->void($record, $data['reason'], auth()->user()), 'Time entry voided')),
            ]),
        ];
    }

    /** "Record time" with a matter picker (the matter tab has its own, without one). */
    public static function recordAction(): Action
    {
        return Action::make('record')
            ->label('Record time')
            ->icon(Heroicon::OutlinedPlus)
            ->visible(fn () => auth()->user()->can('create', TimeEntry::class))
            ->fillForm(fn () => ['worked_on' => now(config('app.firm_timezone'))->toDateString(), 'hours' => 0, 'mins' => 0, 'billable' => true])
            ->schema([self::matterField(), ...self::entryFields()])
            ->action(fn (Action $action, array $data) => DomainActions::run($action,
                fn () => app(TimeEntries::class)->record(self::recordableMatter($data['matter_id']), self::entryData($data), auth()->user()), 'Time recorded'));
    }

    public static function startTimerAction(): Action
    {
        return Action::make('startTimer')
            ->label('Start timer')
            ->icon(Heroicon::OutlinedPlay)
            ->color('gray')
            ->visible(fn () => auth()->user()->can('create', TimeEntry::class))
            ->schema([self::matterField(openOnly: true)])
            ->action(fn (Action $action, array $data) => DomainActions::run($action,
                fn () => app(TimeEntries::class)->startTimer(self::recordableMatter($data['matter_id']), auth()->user()), 'Timer started'));
    }

    /** Finance bills the selected unbilled time on a new draft for that client. */
    public static function draftInvoiceAction(): BulkAction
    {
        return BulkAction::make('draftInvoice')
            ->label('Create draft invoice')
            ->icon(Heroicon::OutlinedDocumentCurrencyDollar)
            ->visible(fn () => auth()->user()->can('create', Invoice::class))
            ->deselectRecordsAfterCompletion()
            ->modalDescription('Selected entries must be for one client and not yet billed. Each becomes a fee line at the staff member\'s hourly rate in this currency.')
            ->schema([Select::make('currency')->options(Money::currencyOptions())->default('NGN')->required()])
            ->action(function (BulkAction $action, Collection $records, array $data) {
                $invoice = DomainActions::run($action,
                    fn () => app(TimeEntries::class)->draftInvoice($records, $data['currency'], auth()->user()), 'Draft invoice created');
                $action->redirect(InvoiceResource::getUrl('view', ['record' => $invoice]));
            });
    }

    private static function matterField(bool $openOnly = false): Select
    {
        return Select::make('matter_id')->label('Matter')->required()->searchable()
            ->options(fn () => self::recordableMatters($openOnly)->mapWithKeys(fn (Matter $m) => [$m->id => "{$m->reference} · {$m->title}"])->all());
    }

    /** Matters the user may record time on: their own matters, or any for full administrators. */
    private static function recordableMatters(bool $openOnly = false): \Illuminate\Support\Collection
    {
        return Matter::query()->visibleTo(auth()->user())->orderByDesc('id')->limit(500)->get()
            ->filter(fn (Matter $m) => (! $openOnly || ! $m->isClosed()) && auth()->user()->can('record', [TimeEntry::class, $m]))->values();
    }

    private static function recordableMatter(mixed $id): Matter
    {
        return Matter::query()->visibleTo(auth()->user())->findOrFail($id);
    }

    public static function getPages(): array
    {
        return ['index' => ListTimeEntries::route('/')];
    }
}
