<?php

namespace App\Filament\Pages;

use App\Domain\Identity\Role;
use App\Domain\Matters\Tasks;
use App\Domain\Matters\TaskStatus;
use App\Filament\Resources\Matters\MatterResource;
use App\Filament\Support\DomainActions;
use App\Models\Matter;
use App\Models\MatterDeadline;
use App\Models\Task;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * The signed-in lawyer's or case officer's open tasks, and the deadlines coming up on matters
 * they can see. Deadlines are entered manually; nothing here is calculated.
 */
class MyWork extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Practice';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'My work';

    protected static ?string $slug = 'my-work';

    /** Upcoming window for the deadlines list; overdue ones always show. */
    public const DEADLINE_DAYS = 30;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && $user->isActive() && ($user->isFullAdministrator() || $user->hasRole(Role::Lawyer, Role::CaseOfficer));
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Task::query()->overdue()->where('assignee_id', auth()->id())->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('My tasks')->schema([EmbeddedTable::make()]),
            Section::make('Deadlines and milestones')
                ->description('Open items due in the next '.self::DEADLINE_DAYS.' days, and any overdue, on matters you can see. Entered manually by the team.')
                ->schema([View::make('filament.my-deadlines')->viewData(fn () => ['deadlines' => $this->deadlines()])]),
        ]);
    }

    /** @return Collection<int, MatterDeadline> */
    public function deadlines(): Collection
    {
        return MatterDeadline::query()
            ->whereNull('completed_at')
            ->where('due_at', '<=', now()->addDays(self::DEADLINE_DAYS))
            ->whereHas('matter', fn (Builder $query) => $query->visibleTo(auth()->user())->where('status', '!=', 'closed'))
            ->with('matter:id,reference,title')
            ->orderBy('due_at')
            ->limit(100)
            ->get();
    }

    public function table(Table $table): Table
    {
        $tz = config('app.firm_timezone');

        return $table
            ->query(fn () => Task::query()
                ->where('assignee_id', auth()->id())
                ->whereHas('matter', fn (Builder $query) => $query->visibleTo(auth()->user()))
                ->with('matter:id,reference,title'))
            ->defaultSort(fn (Builder $query) => $query->orderByRaw('due_at is null')->orderBy('due_at'))
            ->emptyStateHeading('Nothing assigned to you')
            ->columns([
                TextColumn::make('title')->wrap()->searchable()
                    ->description(fn (Task $record) => $record->matter->reference.' · '.$record->matter->title),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (TaskStatus $state) => $state->label())
                    ->color(fn (TaskStatus $state) => $state->isOpen() ? 'warning' : 'gray'),
                TextColumn::make('due_at')->label('Due')->dateTime('j M Y H:i', $tz)->placeholder('—')->sortable()
                    ->color(fn (Task $record) => $record->isOverdue() ? 'danger' : null),
            ])
            ->filters([
                TernaryFilter::make('open')->label('Open only')->default(true)
                    ->queries(true: fn (Builder $query) => $query->open(), false: fn (Builder $query) => $query, blank: fn (Builder $query) => $query),
            ])
            ->recordUrl(fn (Task $record) => MatterResource::getUrl('view', ['record' => $record->matter_id]))
            ->recordActions([
                Action::make('start')
                    ->icon(Heroicon::OutlinedPlay)
                    ->authorize('update')
                    ->visible(fn (Task $record) => $record->status === TaskStatus::Open)
                    ->action(fn (Action $action, Task $record) => DomainActions::run($action,
                        fn () => app(Tasks::class)->start($record, auth()->user()), 'Task started')),
                Action::make('complete')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->authorize('update')
                    ->visible(fn (Task $record) => $record->status->isOpen())
                    ->schema([Textarea::make('note')->label('Completion note')->required()->minLength(3)->maxLength(2000)])
                    ->action(fn (Action $action, Task $record, array $data) => DomainActions::run($action,
                        fn () => app(Tasks::class)->complete($record, $data['note'], auth()->user()), 'Task completed')),
            ]);
    }

    public static function matterUrl(Matter $matter): string
    {
        return MatterResource::getUrl('view', ['record' => $matter]);
    }
}
