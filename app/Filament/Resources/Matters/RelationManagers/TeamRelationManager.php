<?php

namespace App\Filament\Resources\Matters\RelationManagers;

use App\Domain\Identity\Role;
use App\Domain\Matters\Matters;
use App\Filament\Support\DomainActions;
use App\Models\MatterTeamMember;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Who can work on the matter. Only full administrators change the team. */
class TeamRelationManager extends MatterRelationManager
{
    protected static string $relationship = 'team';

    protected static ?string $title = 'Team';

    /** @return array<int, string> */
    private function staffOptions(Role ...$roles): array
    {
        return User::query()->active()->withActiveRole(...$roles)->orderBy('name')->pluck('name', 'id')->all();
    }

    public function table(Table $table): Table
    {
        $tz = config('app.firm_timezone');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'addedBy', 'removedBy']))
            ->columns([
                TextColumn::make('user.name')->label('Name'),
                TextColumn::make('role')->badge()->formatStateUsing(fn (string $state) => MatterTeamMember::ROLES[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'responsible' ? 'primary' : 'gray'),
                TextColumn::make('added_at')->label('Added')->dateTime('j M Y', $tz),
                TextColumn::make('removed_at')->label('Left')->dateTime('j M Y', $tz)->placeholder('—')
                    ->description(fn (MatterTeamMember $record) => $record->removal_reason),
            ])
            ->headerActions([
                Action::make('add')
                    ->label('Add team member')
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->visible(fn () => $this->open() && $this->allowed('manageTeam'))
                    ->schema([
                        Select::make('user_id')->label('Staff member')->required()->searchable()
                            ->options(fn () => $this->staffOptions(Role::Lawyer, Role::CaseOfficer)),
                    ])
                    ->action(fn (Action $action, array $data) => DomainActions::run($action,
                        fn () => app(Matters::class)->addTeamMember($this->matter(), User::findOrFail($data['user_id']), $this->me()), 'Added to the team')),
                Action::make('responsible')
                    ->label('Change responsible lawyer')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->color('gray')
                    ->visible(fn () => $this->open() && $this->allowed('manageTeam'))
                    ->modalDescription('The previous responsible lawyer stays on the team as a member. The client is told who is now responsible.')
                    ->schema([
                        Select::make('user_id')->label('Lawyer')->required()->searchable()
                            ->options(fn () => $this->staffOptions(Role::Lawyer)),
                    ])
                    ->action(fn (Action $action, array $data) => DomainActions::run($action,
                        fn () => app(Matters::class)->setResponsible($this->matter(), User::findOrFail($data['user_id']), $this->me()), 'Responsible lawyer changed')),
            ])
            ->recordActions([
                Action::make('remove')
                    ->icon(Heroicon::OutlinedUserMinus)
                    ->color('danger')
                    ->visible(fn (MatterTeamMember $record) => ! $record->removed_at && $record->role !== 'responsible' && $this->allowed('manageTeam'))
                    ->modalDescription('Access ends immediately and their open tasks on this matter become unassigned.')
                    ->schema([Textarea::make('reason')->required()->minLength(5)->maxLength(500)])
                    ->action(fn (Action $action, MatterTeamMember $record, array $data) => DomainActions::run($action,
                        fn () => app(Matters::class)->removeTeamMember($record, $data['reason'], $this->me()), 'Removed from the team')),
            ]);
    }
}
