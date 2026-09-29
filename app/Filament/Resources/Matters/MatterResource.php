<?php

namespace App\Filament\Resources\Matters;

use App\Domain\Matters\Matters;
use App\Domain\Matters\MatterStatus;
use App\Filament\Resources\Engagements\EngagementResource;
use App\Filament\Resources\Enquiries\EnquiryResource;
use App\Filament\Resources\Matters\Pages\ListMatters;
use App\Filament\Resources\Matters\Pages\MatterConversation;
use App\Filament\Resources\Matters\Pages\ViewMatter;
use App\Filament\Resources\Matters\RelationManagers\DeadlinesRelationManager;
use App\Filament\Resources\Matters\RelationManagers\DocumentRequestsRelationManager;
use App\Filament\Resources\Matters\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Matters\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Matters\RelationManagers\NotarisationRelationManager;
use App\Filament\Resources\Matters\RelationManagers\TasksRelationManager;
use App\Filament\Resources\Matters\RelationManagers\TeamRelationManager;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Support\DomainActions;
use App\Models\Matter;
use App\Models\Party;
use App\Models\Quotation;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class MatterResource extends Resource
{
    protected static ?string $model = Matter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Practice';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'reference';

    /** Administrators see every matter; everyone else only matters they are on the team of. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function statusBadge(TextEntry|TextColumn $component): TextEntry|TextColumn
    {
        return $component->badge()
            ->formatStateUsing(fn (MatterStatus $state) => $state->label())
            ->color(fn (MatterStatus $state) => $state->color());
    }

    public static function infolist(Schema $schema): Schema
    {
        $tz = config('app.firm_timezone');

        return $schema->components([
            Grid::make(3)->columnSpanFull()->schema([
                Section::make('Matter')->columnSpan(2)->columns(2)->schema([
                    TextEntry::make('title')->columnSpanFull(),
                    TextEntry::make('client_summary')->label('Summary (visible to the client)')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('internal_assessment')->label('Internal assessment (staff only)')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('next_action')->label('Next step')->placeholder('—')
                        ->helperText(fn (Matter $record) => $record->next_action ? ($record->next_action_client_visible ? 'Shown to the client' : 'Internal only') : null),
                    TextEntry::make('next_action_due_at')->label('Next step due')->dateTime(timezone: $tz)->placeholder('—'),
                    RepeatableEntry::make('parties')->columnSpanFull()->columns(3)->schema([
                        TextEntry::make('name')->hiddenLabel(),
                        TextEntry::make('role')->hiddenLabel()->badge()->formatStateUsing(fn (string $state) => Party::ROLES[$state] ?? $state),
                        TextEntry::make('notes')->hiddenLabel()->placeholder(''),
                    ]),
                ]),
                Section::make('Status')->columnSpan(1)->schema([
                    TextEntry::make('reference')->copyable(),
                    self::statusBadge(TextEntry::make('status')),
                    TextEntry::make('stage')->formatStateUsing(fn (Matter $record) => $record->stageLabel()),
                    TextEntry::make('client.display_name')->label('Client')
                        ->formatStateUsing(fn (Matter $record) => "{$record->client->display_name} ({$record->client->reference})"),
                    TextEntry::make('service.name')->label('Service')->placeholder('—'),
                    TextEntry::make('responsible.user.name')->label('Responsible lawyer')->placeholder('—'),
                    TextEntry::make('priority')->formatStateUsing(fn (string $state) => Matter::PRIORITIES[$state] ?? $state),
                    TextEntry::make('confidentiality')->formatStateUsing(fn (string $state) => Matter::CONFIDENTIALITY[$state] ?? $state),
                    TextEntry::make('jurisdiction')->placeholder('—'),
                    TextEntry::make('representation_started_at')->label('Representation started')->dateTime(timezone: $tz),
                    TextEntry::make('legal_hold')->label('Legal hold')->formatStateUsing(fn (bool $state) => $state ? 'Yes – records must be kept' : 'No'),
                    TextEntry::make('enquiry.reference')->label('From enquiry')->placeholder('—')
                        ->url(fn (Matter $record) => $record->enquiry && auth()->user()->can('view', $record->enquiry) ? EnquiryResource::getUrl('view', ['record' => $record->enquiry]) : null),
                    TextEntry::make('engagement.reference')->label('Engagement terms')->placeholder('—')
                        ->url(fn (Matter $record) => $record->engagement && auth()->user()->can('view', $record->engagement) ? EngagementResource::getUrl('view', ['record' => $record->engagement]) : null),
                    TextEntry::make('closed_at')->dateTime(timezone: $tz)->placeholder('—')->visible(fn (Matter $record) => $record->isClosed()),
                    TextEntry::make('closing_note')->visible(fn (Matter $record) => $record->isClosed()),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['client', 'service', 'responsible.user']))
            ->defaultSort('opened_at', 'desc')
            ->columns([
                TextColumn::make('reference')->searchable(),
                TextColumn::make('title')->searchable()->limit(40),
                TextColumn::make('client.display_name')->label('Client')->searchable(),
                TextColumn::make('stage')->formatStateUsing(fn (Matter $record) => $record->stageLabel()),
                self::statusBadge(TextColumn::make('status')),
                TextColumn::make('priority')->badge()->formatStateUsing(fn (string $state) => Matter::PRIORITIES[$state] ?? $state)
                    ->color(fn (string $state) => in_array($state, ['high', 'urgent'], true) ? 'danger' : 'gray'),
                TextColumn::make('responsible.user.name')->label('Responsible')->placeholder('—'),
                TextColumn::make('next_action_due_at')->label('Next step due')->since()->sortable()->placeholder('—'),
                TextColumn::make('opened_at')->label('Opened')->date()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(MatterStatus::options())->default(MatterStatus::Open->value),
                SelectFilter::make('priority')->options(Matter::PRIORITIES),
                TernaryFilter::make('mine')->label('My matters')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('activeTeam', fn (Builder $t) => $t->where('user_id', auth()->id())),
                        false: fn (Builder $query) => $query,
                    ),
            ])
            ->recordActions([ViewAction::make()]);
    }

    /** @return list<Action|ActionGroup> each re-checked by Matters */
    public static function workActions(): array
    {
        $service = fn (): Matters => app(Matters::class);
        $me = fn (): User => auth()->user();
        $tz = config('app.firm_timezone');

        return [
            Action::make('stage')
                ->label('Change stage')
                ->icon(Heroicon::OutlinedArrowRightCircle)
                ->authorize('update')
                ->visible(fn (Matter $record) => ! $record->isClosed())
                ->modalDescription('The client sees the new stage and your note in their portal timeline.')
                ->fillForm(fn (Matter $record) => ['stage' => $record->stage])
                ->schema(fn (Matter $record) => [
                    Select::make('stage')->options($record->stageOptions())->required(),
                    Textarea::make('note')->label('Note for the client (optional)')->maxLength(500)->rows(2),
                ])
                ->action(fn (Action $action, Matter $record, array $data) => DomainActions::run($action,
                    fn () => $service()->changeStage($record, $data['stage'], $data['note'] ?? null, $me()), 'Stage updated')),

            Action::make('edit')
                ->label('Edit details')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->authorize('update')
                ->visible(fn (Matter $record) => ! $record->isClosed())
                ->modalWidth('3xl')
                ->fillForm(fn (Matter $record) => $record->only(Matters::EDITABLE))
                ->schema([
                    TextInput::make('title')->required()->maxLength(190),
                    Textarea::make('client_summary')->label('Summary (visible to the client)')->maxLength(5000)->rows(3),
                    Textarea::make('internal_assessment')->label('Internal assessment (staff only)')->maxLength(20000)->rows(5),
                    Grid::make(3)->schema([
                        TextInput::make('jurisdiction')->maxLength(190),
                        Select::make('priority')->options(Matter::PRIORITIES)->required(),
                        Select::make('confidentiality')->options(Matter::CONFIDENTIALITY)->required(),
                    ]),
                    Grid::make(2)->schema([
                        TextInput::make('next_action')->label('Next step')->maxLength(250),
                        DateTimePicker::make('next_action_due_at')->label('Next step due')->timezone($tz)->seconds(false),
                    ]),
                    Toggle::make('next_action_client_visible')->label('Show the next step to the client'),
                ])
                ->action(fn (Action $action, Matter $record, array $data) => DomainActions::run($action,
                    fn () => $service()->update($record, $data, $me()), 'Matter updated')),

            ActionGroup::make([
                Action::make('addParty')
                    ->label('Add party')
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->authorize('update')
                    ->visible(fn (Matter $record) => ! $record->isClosed())
                    ->schema([
                        TextInput::make('name')->required()->maxLength(190),
                        Select::make('role')->options(Party::ROLES)->required(),
                        Textarea::make('notes')->maxLength(1000)->rows(2),
                    ])
                    ->action(fn (Action $action, Matter $record, array $data) => DomainActions::run($action,
                        fn () => $service()->addParty($record, $data['name'], $data['role'], $data['notes'] ?? null, $me()), 'Party added')),

                Action::make('quotation')
                    ->label('Prepare quotation')
                    ->icon(Heroicon::OutlinedCalculator)
                    ->visible(fn (Matter $record) => ! $record->isClosed() && auth()->user()->can('create', Quotation::class))
                    ->url(fn (Matter $record) => QuotationResource::getUrl('create', ['matter' => $record->id])),

                Action::make('hold')
                    ->label('Put on hold')
                    ->icon(Heroicon::OutlinedPauseCircle)
                    ->authorize('update')
                    ->visible(fn (Matter $record) => $record->status === MatterStatus::Open)
                    ->schema([Textarea::make('reason')->label('Reason (internal)')->required()->minLength(5)->maxLength(2000)])
                    ->action(fn (Action $action, Matter $record, array $data) => DomainActions::run($action,
                        fn () => $service()->hold($record, $data['reason'], $me()), 'Matter on hold')),

                Action::make('resume')
                    ->icon(Heroicon::OutlinedPlayCircle)
                    ->authorize('update')
                    ->visible(fn (Matter $record) => $record->status === MatterStatus::OnHold)
                    ->requiresConfirmation()
                    ->action(fn (Action $action, Matter $record) => DomainActions::run($action,
                        fn () => $service()->resume($record, $me()), 'Matter resumed')),

                Action::make('close')
                    ->label('Close matter')
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->color('danger')
                    ->authorize('close')
                    ->modalDescription('All tasks and document requests must be completed or cancelled first. The client is told the matter is closed.')
                    ->schema([
                        ...array_map(fn (string $key, string $label) => Checkbox::make("checklist.{$key}")->label($label)->accepted(),
                            array_keys(Matter::CLOSURE_CHECKLIST), Matter::CLOSURE_CHECKLIST),
                        Textarea::make('note')->label('Closing note (internal)')->required()->minLength(5)->maxLength(5000),
                    ])
                    ->action(fn (Action $action, Matter $record, array $data) => DomainActions::run($action,
                        fn () => $service()->close($record, $data['checklist'] ?? [], $data['note'], $me()), 'Matter closed')),

                Action::make('reopen')
                    ->icon(Heroicon::OutlinedLockOpen)
                    ->authorize('reopen')
                    ->schema([Textarea::make('reason')->required()->minLength(5)->maxLength(2000), DomainActions::currentPasswordField()])
                    ->action(fn (Action $action, Matter $record, array $data) => DomainActions::run($action,
                        fn () => $service()->reopen($record, $data['reason'], $me()), 'Matter reopened')),

                Action::make('legalHold')
                    ->label(fn (Matter $record) => $record->legal_hold ? 'Release legal hold' : 'Place legal hold')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->visible(fn () => auth()->user()->isFullAdministrator())
                    ->modalDescription('A legal hold stops the matter\'s records being deleted under any retention rule.')
                    ->schema([Textarea::make('reason')->required()->minLength(5)->maxLength(2000), DomainActions::currentPasswordField()])
                    ->action(fn (Action $action, Matter $record, array $data) => DomainActions::run($action,
                        fn () => $service()->setLegalHold($record, ! $record->legal_hold, $data['reason'], $me()), 'Legal hold updated')),
            ])->label('More')->icon(Heroicon::OutlinedEllipsisVertical)->button()->color('gray'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            TasksRelationManager::class,
            DocumentsRelationManager::class,
            DocumentRequestsRelationManager::class,
            DeadlinesRelationManager::class,
            NotarisationRelationManager::class,
            TeamRelationManager::class,
            EventsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMatters::route('/'),
            'view' => ViewMatter::route('/{record}'),
            'conversation' => MatterConversation::route('/{record}/conversation'),
        ];
    }
}
