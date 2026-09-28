<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Domain\Clients\ClientContacts;
use App\Filament\Support\DomainActions;
use App\Models\Client;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * People who can use the client portal for this client. Added by emailed invitation only: they set
 * their own password, and no password is ever chosen or sent by staff.
 */
class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contactLinks';

    protected static ?string $title = 'Portal contacts';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    private function client(): Client
    {
        /** @var Client */
        return $this->getOwnerRecord();
    }

    public function table(Table $table): Table
    {
        $tz = config('app.firm_timezone');

        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('email'),
                TextColumn::make('pivot.relationship')->label('Relationship')
                    ->formatStateUsing(fn (?string $state) => $state === 'owner' ? 'Account owner' : 'Authorised contact'),
                TextColumn::make('pivot.created_at')->label('Added')->dateTime('j M Y', $tz),
                TextColumn::make('pivot.revoked_at')->label('Access removed')->dateTime('j M Y', $tz)->placeholder('Active')
                    ->color(fn ($state) => $state ? 'danger' : 'success'),
            ])
            ->headerActions([
                Action::make('invite')
                    ->label('Invite contact')
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->visible(fn () => auth()->user()->can('manageContacts', $this->client()))
                    ->modalDescription('They receive an email link to set their own password. Access starts only when they accept.')
                    ->schema([
                        TextInput::make('name')->required()->maxLength(190),
                        TextInput::make('email')->email()->required()->maxLength(190),
                    ])
                    ->action(fn (Action $action, array $data) => DomainActions::run($action,
                        fn () => app(ClientContacts::class)->invite($this->client(), $data['email'], $data['name'], auth()->user()), 'Invitation sent')),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label('Remove access')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->visible(fn (User $record) => ! $record->pivot?->revoked_at && auth()->user()->can('manageContacts', $this->client()))
                    ->modalDescription('They lose access to this client\'s matters and documents from their next page load.')
                    ->schema([Textarea::make('reason')->required()->minLength(5)->maxLength(1000)])
                    ->action(fn (Action $action, User $record, array $data) => DomainActions::run($action,
                        fn () => app(ClientContacts::class)->revoke($this->client(), $record, auth()->user(), $data['reason']), 'Access removed')),
            ]);
    }
}
