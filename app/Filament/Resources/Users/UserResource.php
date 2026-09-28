<?php

namespace App\Filament\Resources\Users;

use App\Domain\Identity\AccountAdministration;
use App\Domain\Identity\Role;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Support\DomainActions;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Accounts';

    protected static ?string $modelLabel = 'account';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('activeRoleGrants');
    }

    public static function status(User $user): string
    {
        return match (true) {
            $user->offboarded_at !== null => 'Offboarded',
            $user->suspended_at !== null => 'Suspended',
            default => 'Active',
        };
    }

    /** @return list<string> role labels from the eager-loaded grants */
    public static function roleLabels(User $user): array
    {
        return $user->activeRoleGrants->map(fn ($grant) => $grant->role->label())->unique()->values()->all();
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Account')->columns(2)->columnSpanFull()->schema([
                TextEntry::make('name'),
                TextEntry::make('email')->copyable(),
                TextEntry::make('phone')->placeholder('—'),
                TextEntry::make('status')->state(fn (User $record) => self::status($record))->badge()
                    ->color(fn (string $state) => $state === 'Active' ? 'success' : 'danger'),
                TextEntry::make('roles')->state(fn (User $record) => self::roleLabels($record))->badge()->placeholder('No roles'),
                TextEntry::make('two_step')->label('2-step verification')
                    ->state(fn (User $record) => collect([
                        $record->getAppAuthenticationSecret() ? 'Authenticator app' : null,
                        $record->hasEmailAuthentication() ? 'Email code' : null,
                    ])->filter()->implode(', ') ?: 'Not set up yet'),
                TextEntry::make('last_login_at')->label('Last staff sign-in')->dateTime(timezone: config('app.firm_timezone'))->placeholder('Never'),
                TextEntry::make('created_at')->label('Created')->dateTime(timezone: config('app.firm_timezone')),
                TextEntry::make('suspension_reason')->visible(fn (User $record) => $record->suspended_at !== null),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->sortable(),
                TextColumn::make('roles')->state(fn (User $record) => self::roleLabels($record))->badge()->placeholder('No roles'),
                TextColumn::make('status')->state(fn (User $record) => self::status($record))->badge()
                    ->color(fn (string $state) => $state === 'Active' ? 'success' : 'danger'),
                TextColumn::make('last_login_at')->label('Last staff sign-in')->since()->sortable()->placeholder('Never'),
            ])
            ->filters([
                SelectFilter::make('role')->options(Role::options())
                    ->query(fn (Builder $query, array $data) => $data['value']
                        ? $query->withActiveRole(Role::from($data['value'])) : $query),
                TernaryFilter::make('active')
                    ->queries(
                        true: fn (Builder $query) => $query->active(),
                        false: fn (Builder $query) => $query->where(fn ($q) => $q->whereNotNull('suspended_at')->orWhereNotNull('offboarded_at')),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make(self::managementActions()),
            ]);
    }

    /** @return list<Action> */
    public static function managementActions(): array
    {
        $accounts = fn (): AccountAdministration => app(AccountAdministration::class);

        return [
            Action::make('roles')
                ->label('Change staff roles')
                ->icon(Heroicon::OutlinedKey)
                ->authorize('manage')
                ->visible(fn (User $record) => $record->offboarded_at === null)
                ->fillForm(fn (User $record) => ['roles' => array_values(array_intersect($record->activeRoleValues(), array_keys(Role::options(staffOnly: true))))])
                ->schema([
                    CheckboxList::make('roles')->options(Role::options(staffOnly: true))
                        ->helperText('Client portal access is managed from the client record, not here.'),
                    Textarea::make('reason')->label('Reason (kept in the audit log)')->maxLength(500),
                    DomainActions::currentPasswordField(),
                ])
                ->action(fn (Action $action, User $record, array $data) => DomainActions::run($action,
                    fn () => $accounts()->syncStaffRoles($record, array_map(fn ($v) => Role::from($v), $data['roles'] ?? []), auth()->user(), $data['reason'] ?? null),
                    'Roles updated')),

            Action::make('suspend')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                ->authorize('manage')
                ->visible(fn (User $record) => $record->isActive())
                ->modalDescription('The person is signed out everywhere and cannot sign in until reinstated.')
                ->schema([
                    Textarea::make('reason')->required()->maxLength(500),
                    DomainActions::currentPasswordField(),
                ])
                ->action(fn (Action $action, User $record, array $data) => DomainActions::run($action,
                    fn () => $accounts()->suspend($record, auth()->user(), $data['reason']), 'Account suspended')),

            Action::make('reinstate')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('success')
                ->authorize('manage')
                ->visible(fn (User $record) => $record->isSuspended() && $record->offboarded_at === null)
                ->schema([DomainActions::currentPasswordField()])
                ->action(fn (Action $action, User $record) => DomainActions::run($action,
                    fn () => $accounts()->reinstate($record, auth()->user()), 'Account reinstated')),

            Action::make('revokeSessions')
                ->label('Sign out everywhere')
                ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
                ->authorize('manage')
                ->requiresConfirmation()
                ->modalDescription('Ends every current session and remembered sign-in for this person.')
                ->action(fn (Action $action, User $record) => DomainActions::run($action,
                    fn () => $accounts()->revokeSessions($record, auth()->user()), 'Sessions ended')),

            Action::make('offboard')
                ->icon(Heroicon::OutlinedUserMinus)
                ->color('danger')
                ->authorize('manage')
                ->visible(fn (User $record) => $record->offboarded_at === null && $record->isStaff())
                ->modalDescription('Removes every staff role and ends all sessions. The account and its history are kept for the record.')
                ->schema([
                    Textarea::make('reason')->required()->maxLength(500),
                    DomainActions::currentPasswordField(),
                ])
                ->action(fn (Action $action, User $record, array $data) => DomainActions::run($action,
                    fn () => $accounts()->offboard($record, auth()->user(), $data['reason']), 'Staff member offboarded')),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'view' => ViewUser::route('/{record}'),
        ];
    }
}
