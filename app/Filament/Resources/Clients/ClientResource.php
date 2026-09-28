<?php

namespace App\Filament\Resources\Clients;

use App\Domain\Clients\ClientRecords;
use App\Domain\Identity\Role;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\Clients\RelationManagers\MattersRelationManager;
use App\Filament\Support\DomainActions;
use App\Models\Client;
use App\Models\User;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Clients are created from enquiries and never deleted. Staff below full administrator see only
 * clients reachable through their matters or the enquiries they own.
 */
class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Practice';

    protected static ?int $navigationSort = 15;

    protected static ?string $recordTitleAttribute = 'display_name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(3)->schema([
                TextEntry::make('reference')->copyable(),
                TextEntry::make('display_name')->label('Name'),
                TextEntry::make('type')->formatStateUsing(fn (string $state) => ClientRecords::TYPES[$state] ?? $state),
                TextEntry::make('organisation_name')->placeholder('—'),
                TextEntry::make('registration_number')->placeholder('—'),
                TextEntry::make('email')->placeholder('—'),
                TextEntry::make('phone')->placeholder('—'),
                TextEntry::make('country')->placeholder('—'),
                TextEntry::make('timezone'),
                TextEntry::make('preferred_currency')->label('Currency'),
                TextEntry::make('relationshipOwner.name')->label('Relationship owner')->placeholder('—'),
                TextEntry::make('address')->placeholder('—'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('relationshipOwner')->withCount('matters'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('reference')->searchable(),
                TextColumn::make('display_name')->label('Name')->searchable(),
                TextColumn::make('type')->formatStateUsing(fn (string $state) => ClientRecords::TYPES[$state] ?? $state),
                TextColumn::make('email')->searchable()->toggleable(),
                TextColumn::make('matters_count')->label('Matters'),
                TextColumn::make('relationshipOwner.name')->label('Relationship owner')->placeholder('—'),
                TextColumn::make('created_at')->label('Since')->date()->sortable(),
            ])
            ->filters([SelectFilter::make('type')->options(ClientRecords::TYPES)])
            ->recordActions([ViewAction::make()]);
    }

    public static function editAction(): Action
    {
        return Action::make('edit')
            ->label('Edit details')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->authorize('update')
            ->modalWidth('3xl')
            ->fillForm(fn (Client $record) => $record->only(ClientRecords::EDITABLE))
            ->schema([
                Grid::make(2)->schema([
                    TextInput::make('display_name')->label('Name')->required()->maxLength(190),
                    Select::make('type')->options(ClientRecords::TYPES)->required(),
                    TextInput::make('organisation_name')->maxLength(190),
                    TextInput::make('registration_number')->maxLength(190),
                    TextInput::make('email')->email()->maxLength(190),
                    TextInput::make('phone')->tel()->maxLength(40),
                    TextInput::make('country')->label('Country code')->length(2)->helperText('Two letters, e.g. NG.'),
                    Select::make('timezone')->options(fn () => array_combine(timezone_identifiers_list(), timezone_identifiers_list()))->searchable()->required(),
                    Select::make('preferred_currency')->label('Currency')->options(Money::currencyOptions())->required(),
                    Select::make('relationship_owner_id')->label('Relationship owner')
                        ->options(fn () => User::query()->active()->withActiveRole(Role::Lawyer, Role::FirmPrincipal)->orderBy('name')->pluck('name', 'id')),
                ]),
                Textarea::make('address')->maxLength(1000)->rows(2),
            ])
            ->action(fn (Action $action, Client $record, array $data) => DomainActions::run($action,
                fn () => app(ClientRecords::class)->update($record, $data, auth()->user()), 'Client updated'));
    }

    public static function getRelations(): array
    {
        return [ContactsRelationManager::class, MattersRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
            'view' => ViewClient::route('/{record}'),
        ];
    }
}
