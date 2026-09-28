<?php

namespace App\Filament\Resources\Matters\RelationManagers;

use App\Domain\Matters\Tasks;
use App\Domain\Matters\TaskStatus;
use App\Filament\Support\DomainActions;
use App\Models\Task;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Internal work. Tasks are never shown to the client. */
class TasksRelationManager extends MatterRelationManager
{
    protected static string $relationship = 'tasks';

    protected static ?string $title = 'Tasks (internal)';

    private function tasks(): Tasks
    {
        return app(Tasks::class);
    }

    /** Fields shared by create and edit. $except is the task being edited, so it can't depend on itself. */
    private function taskFields(?Task $except = null): array
    {
        $tz = config('app.firm_timezone');
        $others = $this->matter()->tasks()->when($except, fn (Builder $query) => $query->whereKeyNot($except->id))
            ->orderBy('title')->pluck('title', 'id')->all();

        return [
            TextInput::make('title')->required()->maxLength(190),
            Textarea::make('description')->maxLength(5000)->rows(3),
            Select::make('assignee_id')->label('Assigned to')->options($this->teamOptions())
                ->helperText('Only people on the matter team can be assigned.'),
            Grid::make(2)->schema([
                DateTimePicker::make('due_at')->label('Due')->timezone($tz)->seconds(false),
                DateTimePicker::make('remind_at')->label('Remind the assignee at')->timezone($tz)->seconds(false),
            ]),
            Grid::make(2)->schema([
                Select::make('depends_on_id')->label('Waits for')->options($others)->searchable(),
                Select::make('parent_id')->label('Subtask of')->options($others)->searchable(),
            ]),
        ];
    }

    public function table(Table $table): Table
    {
        $tz = config('app.firm_timezone');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['assignee', 'dependsOn', 'parent']))
            ->defaultSort('due_at')
            ->columns([
                TextColumn::make('title')->searchable()->wrap()
                    ->description(fn (Task $record) => $record->parent ? "Subtask of: {$record->parent->title}" : null),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (TaskStatus $state) => $state->label())
                    ->color(fn (TaskStatus $state) => $state->color()),
                TextColumn::make('assignee.name')->label('Assigned to')->placeholder('Unassigned'),
                TextColumn::make('due_at')->label('Due')->dateTime('j M Y H:i', $tz)->sortable()->placeholder('—')
                    ->color(fn (Task $record) => $record->isOverdue() ? 'danger' : null),
                TextColumn::make('dependsOn.title')->label('Waits for')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('completion_note')->label('Outcome')->placeholder('—')->limit(50)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('open')->label('Open only')->default(true)
                    ->queries(true: fn (Builder $query) => $query->open(), false: fn (Builder $query) => $query),
            ])
            ->headerActions([
                Action::make('create')
                    ->label('New task')
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible(fn () => $this->open() && $this->allowed('manageTasks'))
                    ->schema(fn () => $this->taskFields())
                    ->action(fn (Action $action, array $data) => DomainActions::run($action,
                        fn () => $this->tasks()->create($this->matter(), $data, $this->me()), 'Task created')),
            ])
            ->recordActions([
                Action::make('start')
                    ->icon(Heroicon::OutlinedPlay)
                    ->authorize('update')
                    ->visible(fn (Task $record) => $record->status === TaskStatus::Open)
                    ->action(fn (Action $action, Task $record) => DomainActions::run($action,
                        fn () => $this->tasks()->start($record, $this->me()), 'Task started')),
                Action::make('complete')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->authorize('update')
                    ->visible(fn (Task $record) => $record->status->isOpen())
                    ->schema([Textarea::make('note')->label('Completion note')->required()->minLength(3)->maxLength(2000)])
                    ->action(fn (Action $action, Task $record, array $data) => DomainActions::run($action,
                        fn () => $this->tasks()->complete($record, $data['note'], $this->me()), 'Task completed')),
                ActionGroup::make([
                    Action::make('edit')
                        ->icon(Heroicon::OutlinedPencilSquare)
                        ->authorize('update')
                        ->visible(fn (Task $record) => $record->status->isOpen())
                        ->fillForm(fn (Task $record) => $record->only(['title', 'description', 'assignee_id', 'due_at', 'remind_at', 'depends_on_id', 'parent_id']))
                        ->schema(fn (Task $record) => $this->taskFields($record))
                        ->action(fn (Action $action, Task $record, array $data) => DomainActions::run($action,
                            fn () => $this->tasks()->update($record, $data, $this->me()), 'Task updated')),
                    Action::make('cancel')
                        ->icon(Heroicon::OutlinedXMark)
                        ->color('danger')
                        ->authorize('update')
                        ->visible(fn (Task $record) => $record->status->isOpen())
                        ->schema([Textarea::make('reason')->required()->minLength(5)->maxLength(2000)])
                        ->action(fn (Action $action, Task $record, array $data) => DomainActions::run($action,
                            fn () => $this->tasks()->cancel($record, $data['reason'], $this->me()), 'Task cancelled')),
                ]),
            ]);
    }
}
