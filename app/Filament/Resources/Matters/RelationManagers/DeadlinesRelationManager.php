<?php

namespace App\Filament\Resources\Matters\RelationManagers;

use App\Domain\Matters\Matters;
use App\Filament\Support\DomainActions;
use App\Models\MatterDeadline;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Deadlines are entered by staff. Nothing is calculated from statute or court rules. */
class DeadlinesRelationManager extends MatterRelationManager
{
    protected static string $relationship = 'deadlines';

    protected static ?string $title = 'Deadlines & milestones';

    public function table(Table $table): Table
    {
        $tz = config('app.firm_timezone');

        return $table
            ->description('Entered manually. The system does not calculate legal deadlines — verify every date.')
            ->columns([
                TextColumn::make('title')->wrap()->description(fn (MatterDeadline $record) => $record->notes),
                TextColumn::make('kind')->badge()->formatStateUsing(fn (string $state) => MatterDeadline::KINDS[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'deadline' ? 'danger' : 'info'),
                TextColumn::make('due_at')->label('Due')->dateTime('j M Y H:i', $tz)
                    ->color(fn (MatterDeadline $record) => ! $record->completed_at && $record->due_at->isPast() ? 'danger' : null),
                IconColumn::make('client_visible')->label('Client sees')->boolean(),
                TextColumn::make('completed_at')->label('Completed')->dateTime('j M Y', $tz)->placeholder('—'),
            ])
            ->headerActions([
                Action::make('add')
                    ->label('Add deadline')
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible(fn () => $this->open() && $this->allowed('update'))
                    ->schema([
                        Select::make('kind')->options(MatterDeadline::KINDS)->required()->default('milestone'),
                        TextInput::make('title')->required()->maxLength(190),
                        DateTimePicker::make('due_at')->label('Due')->required()->timezone($tz)->seconds(false),
                        Textarea::make('notes')->maxLength(2000)->rows(2),
                        Toggle::make('client_visible')->label('Show to the client'),
                    ])
                    ->action(fn (Action $action, array $data) => DomainActions::run($action,
                        fn () => app(Matters::class)->addDeadline($this->matter(), $data, $this->me()), 'Deadline added')),
            ])
            ->recordActions([
                Action::make('complete')
                    ->label('Mark done')
                    ->icon(Heroicon::OutlinedCheck)
                    ->visible(fn (MatterDeadline $record) => ! $record->completed_at && $this->allowed('update'))
                    ->requiresConfirmation()
                    ->action(fn (Action $action, MatterDeadline $record) => DomainActions::run($action,
                        fn () => app(Matters::class)->completeDeadline($record, $this->me()), 'Marked done')),
            ]);
    }
}
