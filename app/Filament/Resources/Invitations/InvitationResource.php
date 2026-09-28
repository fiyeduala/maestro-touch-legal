<?php

namespace App\Filament\Resources\Invitations;

use App\Domain\Identity\Invitations;
use App\Domain\Identity\Role;
use App\Filament\Resources\Invitations\Pages\ListInvitations;
use App\Filament\Support\DomainActions;
use App\Models\Invitation;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class InvitationResource extends Resource
{
    protected static ?string $model = Invitation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Staff invitations';

    protected static ?string $recordTitleAttribute = 'email';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('invitedBy'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('email')->searchable(),
                TextColumn::make('name')->searchable()->placeholder('—'),
                TextColumn::make('roles')->badge()
                    ->formatStateUsing(fn (string $state) => Role::tryFrom($state)?->label() ?? $state),
                TextColumn::make('status')->state(fn (Invitation $record) => $record->status())->badge()
                    ->color(fn (string $state) => match ($state) {
                        'pending' => 'info', 'accepted' => 'success', default => 'gray',
                    }),
                TextColumn::make('invitedBy.name')->label('Invited by')->placeholder('—'),
                TextColumn::make('expires_at')->label('Link expires')->since()->sortable(),
                TextColumn::make('send_count')->label('Sent')->suffix('×'),
            ])
            ->filters([
                Filter::make('open')->label('Open only')->default()
                    ->query(fn (Builder $query) => $query->whereNull('accepted_at')->whereNull('revoked_at')),
            ])
            ->recordActions([
                Action::make('resend')
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->authorize('manage')
                    ->requiresConfirmation()
                    ->modalDescription('Sends a fresh link valid for 7 days. Any earlier link stops working.')
                    ->action(fn (Action $action, Invitation $record) => DomainActions::run($action,
                        fn () => app(Invitations::class)->resend($record, auth()->user()), 'Invitation sent again')),
                Action::make('revoke')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->authorize('manage')
                    ->requiresConfirmation()
                    ->action(fn (Action $action, Invitation $record) => DomainActions::run($action,
                        fn () => app(Invitations::class)->revoke($record, auth()->user()), 'Invitation revoked')),
            ]);
    }

    public static function inviteAction(): Action
    {
        return Action::make('invite')
            ->label('Invite staff')
            ->icon(Heroicon::OutlinedUserPlus)
            ->authorize('create', Invitation::class)
            ->modalDescription('The person receives an email link to set their own password and 2-step verification. No password is ever sent by email.')
            ->schema([
                TextInput::make('email')->email()->required()->maxLength(255),
                TextInput::make('name')->required()->maxLength(255),
                CheckboxList::make('roles')->options(Role::options(staffOnly: true))->required()->minItems(1),
            ])
            ->action(fn (Action $action, array $data) => DomainActions::run($action,
                fn () => app(Invitations::class)->issue(
                    strtolower(trim($data['email'])), $data['name'],
                    array_map(fn ($v) => Role::from($v), $data['roles']), auth()->user(),
                ), 'Invitation sent'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvitations::route('/'),
        ];
    }
}
