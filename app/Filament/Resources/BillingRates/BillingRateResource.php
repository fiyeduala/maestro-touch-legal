<?php

namespace App\Filament\Resources\BillingRates;

use App\Domain\Billing\TimeEntries;
use App\Filament\Resources\BillingRates\Pages\ListBillingRates;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\DomainActions;
use App\Models\BillingRate;
use App\Models\Matter;
use App\Models\User;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Hourly rates used to bill recorded time (D53). One default rate per person and currency, optionally
 * overridden for a single matter. Changing a rate never changes time already on an invoice.
 */
class BillingRateResource extends Resource
{
    protected static ?string $model = BillingRate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Hourly rates';

    protected static ?string $modelLabel = 'hourly rate';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'matter']))
            ->defaultSort('user_id')
            ->description('Time is billed at the rate for that matter if one is set, otherwise the person\'s default rate in the invoice currency.')
            ->columns([
                TextColumn::make('user.name')->label('Staff member')->searchable()->sortable(),
                TextColumn::make('matter.reference')->label('Applies to')->placeholder('All matters (default)')->wrap()
                    ->description(fn (BillingRate $record) => $record->matter?->title),
                TextColumn::make('currency'),
                TextColumn::make('hourly_minor')->label('Per hour')->state(fn (BillingRate $record) => Money::format($record->hourly_minor, $record->currency)),
                TextColumn::make('updated_at')->label('Last changed')->date('j M Y'),
            ])
            ->filters([
                SelectFilter::make('currency')->options(Money::currencyOptions()),
            ])
            ->recordActions([ActionGroup::make([
                Action::make('change')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->authorize('update')
                    ->fillForm(fn (BillingRate $record) => ['rate' => Money::input($record->hourly_minor)])
                    ->schema([InvoiceResource::amountField('rate', 'Hourly rate')])
                    ->action(fn (Action $action, BillingRate $record, array $data) => DomainActions::run($action,
                        fn () => app(TimeEntries::class)->setRate($record->user, $record->matter, $record->currency, Money::parse($data['rate']), auth()->user()), 'Rate saved')),
                Action::make('remove')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->authorize('delete')
                    ->requiresConfirmation()
                    ->modalDescription('Time already on invoices keeps its rate. Unbilled time for this person can only be billed once another rate applies.')
                    ->action(fn (Action $action, BillingRate $record) => DomainActions::run($action,
                        fn () => app(TimeEntries::class)->removeRate($record, auth()->user()), 'Rate removed')),
            ])]);
    }

    public static function setAction(): Action
    {
        return Action::make('set')
            ->label('Set a rate')
            ->icon(Heroicon::OutlinedPlus)
            ->visible(fn () => auth()->user()->can('create', BillingRate::class))
            ->fillForm(['currency' => 'NGN'])
            ->schema([
                Grid::make(2)->schema([
                    Select::make('user_id')->label('Staff member')->required()->searchable()
                        ->options(fn () => User::query()->active()->withActiveRole(...TimeEntries::RECORDING_ROLES)->orderBy('name')->pluck('name', 'id')->all()),
                    Select::make('matter_id')->label('For one matter only (optional)')->searchable()
                        ->placeholder('All matters (default)')
                        ->getSearchResultsUsing(fn (string $search) => Matter::query()
                            ->where(fn (Builder $q) => $q->where('reference', 'like', self::like($search))->orWhere('title', 'like', self::like($search)))
                            ->orderByDesc('id')->limit(20)->get()->mapWithKeys(fn (Matter $m) => [$m->id => "{$m->reference} · {$m->title}"])->all())
                        ->getOptionLabelUsing(fn ($value) => Matter::find($value)?->reference),
                    Select::make('currency')->options(Money::currencyOptions())->required(),
                    InvoiceResource::amountField('rate', 'Hourly rate'),
                ]),
            ])
            ->modalDescription('If the person already has a rate for that currency (and matter), it is replaced.')
            ->action(fn (Action $action, array $data) => DomainActions::run($action, fn () => app(TimeEntries::class)->setRate(
                User::findOrFail($data['user_id']),
                filled($data['matter_id'] ?? null) ? Matter::findOrFail($data['matter_id']) : null,
                $data['currency'], Money::parse($data['rate']), auth()->user(),
            ), 'Rate saved'));
    }

    private static function like(string $search): string
    {
        return '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
    }

    public static function getPages(): array
    {
        return ['index' => ListBillingRates::route('/')];
    }
}
