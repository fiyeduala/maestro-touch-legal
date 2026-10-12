<?php

namespace App\Filament\Resources\Matters\RelationManagers;

use App\Domain\Billing\TimeEntries;
use App\Filament\Resources\TimeEntries\TimeEntryResource;
use App\Filament\Support\DomainActions;
use App\Models\TimeEntry;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Time recorded on this matter (D53). Billing happens from invoices, not here. */
class TimeRelationManager extends MatterRelationManager
{
    protected static string $relationship = 'timeEntries';

    protected static ?string $title = 'Time';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return parent::canViewForRecord($ownerRecord, $pageClass) && (auth()->user()?->can('viewAny', TimeEntry::class) ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'invoice', 'matter']))
            ->defaultSort('worked_on', 'desc')
            ->description(fn () => $this->totals())
            ->columns([
                TextColumn::make('worked_on')->label('Date')->date('j M Y'),
                TextColumn::make('user.name')->label('Who'),
                ...TimeEntryResource::entryColumns(),
            ])
            ->headerActions([
                Action::make('startTimer')
                    ->label('Start timer')
                    ->icon(Heroicon::OutlinedPlay)
                    ->color('gray')
                    ->visible(fn () => $this->open() && $this->me()->can('record', [TimeEntry::class, $this->matter()]))
                    ->action(fn (Action $action) => DomainActions::run($action,
                        fn () => app(TimeEntries::class)->startTimer($this->matter(), $this->me()), 'Timer started')),
                Action::make('record')
                    ->label('Record time')
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible(fn () => $this->me()->can('record', [TimeEntry::class, $this->matter()]))
                    ->fillForm(fn () => ['worked_on' => now(config('app.firm_timezone'))->toDateString(), 'hours' => 0, 'mins' => 0, 'billable' => true])
                    ->schema(TimeEntryResource::entryFields())
                    ->action(fn (Action $action, array $data) => DomainActions::run($action,
                        fn () => app(TimeEntries::class)->record($this->matter(), TimeEntryResource::entryData($data), $this->me()), 'Time recorded')),
            ])
            ->recordActions(TimeEntryResource::entryActions());
    }

    private function totals(): string
    {
        $live = $this->matter()->timeEntries()->whereNull('voided_at')->whereNotNull('minutes');
        $all = (clone $live)->sum('minutes');
        $unbilled = (clone $live)->unbilled()->sum('minutes');

        return 'Recorded: '.TimeEntry::duration((int) $all).' · Not yet billed: '.TimeEntry::duration((int) $unbilled)
            .'. Finance adds unbilled time to a draft invoice.';
    }
}
