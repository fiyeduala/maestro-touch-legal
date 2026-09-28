<?php

namespace App\Filament\Resources\Enquiries;

use App\Domain\Engagement\Engagements;
use App\Domain\Engagement\OfferStatus;
use App\Domain\Identity\Role;
use App\Domain\Intake\ConflictChecks;
use App\Domain\Intake\Enquiries;
use App\Domain\Intake\EnquirySource;
use App\Domain\Intake\EnquiryStatus;
use App\Filament\Resources\Engagements\EngagementResource;
use App\Filament\Resources\Enquiries\Pages\ListEnquiries;
use App\Filament\Resources\Enquiries\Pages\ViewEnquiry;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Support\DomainActions;
use App\Models\Client;
use App\Models\Engagement;
use App\Models\EngagementTemplate;
use App\Models\Enquiry;
use App\Models\EnquiryEvent;
use App\Models\Party;
use App\Models\Quotation;
use App\Models\QuotationVersion;
use App\Models\Service;
use App\Models\User;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class EnquiryResource extends Resource
{
    protected static ?string $model = Enquiry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Practice';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'reference';

    /** Administrators see every enquiry; lawyers and case officers only those they own. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function getNavigationBadge(): ?string
    {
        $new = Enquiry::query()->visibleTo(auth()->user())->whereIn('status', [EnquiryStatus::New, EnquiryStatus::Triage])->count();

        return $new ? (string) $new : null;
    }

    public static function conflictColor(string $state): string
    {
        return match ($state) {
            'cleared' => 'success',
            'flagged' => 'danger',
            default => 'warning',
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        $tz = config('app.firm_timezone');
        $offerStatus = fn () => TextEntry::make('status')->badge()
            ->formatStateUsing(fn (OfferStatus $state) => $state->label())
            ->color(fn (OfferStatus $state) => $state->color());

        return $schema->components([
            Grid::make(3)->columnSpanFull()->schema([
                Section::make('Enquirer')->columnSpan(2)->columns(2)->schema([
                    TextEntry::make('contact_name')->label('Name'),
                    TextEntry::make('contact_email')->label('Email')->copyable()
                        ->helperText('Not verified: confirm identity before sharing anything confidential.'),
                    TextEntry::make('contact_phone')->label('Phone')->placeholder('—'),
                    TextEntry::make('organisation_name')->label('Organisation')->placeholder('—'),
                    TextEntry::make('service.name')->label('Service')->placeholder('Not specified'),
                    TextEntry::make('preferred_times')->placeholder('—'),
                    TextEntry::make('summary')->columnSpanFull(),
                    RepeatableEntry::make('answers')->label('Intake answers')->columnSpanFull()->columns(2)
                        ->visible(fn (Enquiry $record) => filled($record->answers))
                        ->schema([
                            TextEntry::make('label')->hiddenLabel()->weight('bold'),
                            TextEntry::make('value')->hiddenLabel()->placeholder('—'),
                        ]),
                ]),
                Section::make('Status')->columnSpan(1)->schema([
                    TextEntry::make('reference')->copyable(),
                    TextEntry::make('status')->badge()
                        ->formatStateUsing(fn (EnquiryStatus $state) => $state->label())
                        ->color(fn (EnquiryStatus $state) => $state->color()),
                    TextEntry::make('conflict_status')->label('Conflict check')->badge()
                        ->formatStateUsing(fn (string $state) => ucfirst($state))
                        ->color(fn (string $state) => self::conflictColor($state)),
                    TextEntry::make('owner.name')->label('Owner')->placeholder('Unassigned'),
                    TextEntry::make('client.display_name')->label('Client record')->placeholder('Not linked')
                        ->formatStateUsing(fn (Enquiry $record) => "{$record->client->display_name} ({$record->client->reference})"),
                    TextEntry::make('matter.reference')->label('Matter')->placeholder('—'),
                    TextEntry::make('source')->formatStateUsing(fn (EnquirySource $state) => $state->label()),
                    TextEntry::make('follow_up_on')->label('Follow up')->date()->placeholder('—'),
                    TextEntry::make('created_at')->label('Received')->dateTime(timezone: $tz),
                    TextEntry::make('closure_reason')->placeholder('—')->visible(fn (Enquiry $record) => filled($record->closure_reason)),
                ]),
            ]),
            Section::make('Parties and conflict check')->columnSpanFull()->schema([
                RepeatableEntry::make('parties')->hiddenLabel()->columns(3)->schema([
                    TextEntry::make('name'),
                    TextEntry::make('role')->badge()->formatStateUsing(fn (string $state) => Party::ROLES[$state] ?? $state),
                    TextEntry::make('notes')->placeholder('—'),
                ]),
                RepeatableEntry::make('conflictReviews')->label('Decisions')->columns(3)
                    ->visible(fn (Enquiry $record) => $record->conflictReviews->isNotEmpty())
                    ->schema([
                        TextEntry::make('decision')->badge()->color(fn (string $state) => self::conflictColor($state)),
                        TextEntry::make('reviewer.name')->label('By')->placeholder('—'),
                        TextEntry::make('created_at')->label('When')->dateTime(timezone: $tz),
                        TextEntry::make('reason')->columnSpanFull(),
                    ]),
            ]),
            Section::make('Quotations and engagement terms')->columnSpanFull()
                ->visible(fn (Enquiry $record) => $record->quotations->isNotEmpty() || $record->engagements->isNotEmpty())
                ->schema([
                    RepeatableEntry::make('quotations')->columns(3)
                        ->visible(fn (Enquiry $record) => $record->quotations->isNotEmpty())
                        ->schema([
                            TextEntry::make('reference')->color('primary')
                                ->url(fn (Quotation $record) => QuotationResource::getUrl('view', ['record' => $record])),
                            TextEntry::make('title'),
                            $offerStatus(),
                        ]),
                    RepeatableEntry::make('engagements')->label('Engagement terms')->columns(3)
                        ->visible(fn (Enquiry $record) => $record->engagements->isNotEmpty())
                        ->schema([
                            TextEntry::make('reference')->color('primary')
                                ->url(fn (Engagement $record) => EngagementResource::getUrl('view', ['record' => $record])),
                            TextEntry::make('title'),
                            $offerStatus(),
                        ]),
                ]),
            Section::make('History (internal)')->columnSpanFull()->schema([
                RepeatableEntry::make('events')->hiddenLabel()->columns(3)->schema([
                    TextEntry::make('created_at')->label('When')->dateTime(timezone: $tz),
                    TextEntry::make('type')->badge()
                        ->formatStateUsing(fn (EnquiryEvent $record) => str($record->type)->headline()
                            .($record->to_status ? ' → '.(EnquiryStatus::tryFrom($record->to_status)?->label() ?? $record->to_status) : '')),
                    TextEntry::make('actor.name')->label('By')->placeholder('Enquirer / system'),
                    TextEntry::make('body')->hiddenLabel()->placeholder('')->columnSpanFull(),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $open = array_map(fn ($s) => $s->value, array_filter(EnquiryStatus::cases(), fn ($s) => $s->isOpen()));

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['owner', 'service']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('reference')->searchable(),
                TextColumn::make('contact_name')->label('Enquirer')->searchable()
                    ->description(fn (Enquiry $record) => $record->organisation_name),
                TextColumn::make('contact_email')->label('Email')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('service.name')->label('Service')->placeholder('—'),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (EnquiryStatus $state) => $state->label())
                    ->color(fn (EnquiryStatus $state) => $state->color()),
                TextColumn::make('conflict_status')->label('Conflict')->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->color(fn (string $state) => self::conflictColor($state)),
                TextColumn::make('owner.name')->label('Owner')->placeholder('Unassigned'),
                TextColumn::make('follow_up_on')->label('Follow up')->date()->sortable()->placeholder('—'),
                TextColumn::make('created_at')->label('Received')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(EnquiryStatus::options())->multiple(),
                TernaryFilter::make('open')->label('Open enquiries')->default(true)
                    ->queries(
                        true: fn (Builder $query) => $query->whereIn('status', $open),
                        false: fn (Builder $query) => $query->whereNotIn('status', $open),
                    ),
                SelectFilter::make('conflict_status')->label('Conflict')->options(['pending' => 'Pending', 'cleared' => 'Cleared', 'flagged' => 'Flagged']),
                SelectFilter::make('service_id')->label('Service')->relationship('service', 'name'),
                TernaryFilter::make('unassigned')->label('Unassigned')
                    ->queries(true: fn (Builder $query) => $query->whereNull('owner_id'), false: fn (Builder $query) => $query->whereNotNull('owner_id')),
            ])
            ->recordActions([ViewAction::make()]);
    }

    /** Staff-entered enquiry (phone, WhatsApp, email, walk-in…). */
    public static function recordAction(): Action
    {
        return Action::make('record')
            ->label('Record enquiry')
            ->icon(Heroicon::OutlinedPlus)
            ->authorize('create', Enquiry::class)
            ->modalDescription('For enquiries received by phone, WhatsApp, email or in person. Contact details are not verified.')
            ->schema([
                Grid::make(2)->schema([
                    Select::make('source')->label('Received by')->options(EnquirySource::staffOptions())->required(),
                    Select::make('service_id')->label('Service')
                        ->options(fn () => Service::where('is_active', true)->orderBy('sort')->pluck('name', 'id')),
                    TextInput::make('contact_name')->required()->maxLength(150),
                    TextInput::make('contact_email')->email()->required()->maxLength(190),
                    TextInput::make('contact_phone')->tel()->maxLength(40),
                    TextInput::make('organisation_name')->maxLength(190),
                    Select::make('owner_id')->label('Owner')->visible(fn () => auth()->user()->isFullAdministrator())
                        ->options(fn () => self::ownerOptions()),
                ]),
                Textarea::make('summary')->required()->maxLength(5000)->rows(5),
                Textarea::make('preferred_times')->label('Preferred contact times')->maxLength(500)->rows(2),
            ])
            ->action(function (Action $action, array $data) {
                $enquiry = DomainActions::run($action, fn () => app(Enquiries::class)->createByStaff($data, auth()->user()), 'Enquiry recorded');
                $action->redirect(self::getUrl('view', ['record' => $enquiry]));
            });
    }

    /** @return array<int, string> */
    public static function ownerOptions(): array
    {
        return User::active()->withActiveRole(Role::Lawyer, Role::CaseOfficer, ...Role::fullAdministratorRoles())
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return list<Action|ActionGroup> each re-checked by the domain services */
    public static function workActions(): array
    {
        $service = fn (): Enquiries => app(Enquiries::class);
        $me = fn (): User => auth()->user();

        return [
            Action::make('conflict')
                ->label('Conflict check')
                ->icon(Heroicon::OutlinedShieldExclamation)
                ->color('warning')
                ->authorize('decideConflict')
                ->visible(fn (Enquiry $record) => $record->status->isOpen())
                ->modalWidth('4xl')
                ->modalDescription('Search results are suggestions only. Review them yourself, then record your decision and what you checked. Nothing is cleared automatically.')
                ->modalContent(fn (Enquiry $record) => view('filament.conflict-suggestions', [
                    'terms' => app(ConflictChecks::class)->searchTerms($record),
                    'suggestions' => app(ConflictChecks::class)->suggestions($record, auth()->user()),
                ]))
                ->schema([
                    Select::make('decision')->options(ConflictChecks::DECISIONS)->required(),
                    Textarea::make('reason')->label('Reason and what was checked')->required()->minLength(10)->maxLength(2000)->rows(4),
                ])
                ->action(fn (Action $action, Enquiry $record, array $data) => DomainActions::run($action,
                    fn () => app(ConflictChecks::class)->decide($record, $data['decision'], $data['reason'], $me()), 'Conflict decision recorded')),

            Action::make('status')
                ->label('Change status')
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->authorize('update')
                ->visible(fn (Enquiry $record) => $record->status->allowedTransitions() !== [])
                ->schema(fn (Enquiry $record) => [
                    Select::make('to')->label('New status')->required()->live()
                        ->options(collect($record->status->allowedTransitions())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
                    Textarea::make('reason')->maxLength(2000)->rows(3)
                        ->required(fn (Get $get) => in_array($get('to'), [EnquiryStatus::Declined->value, EnquiryStatus::Closed->value], true))
                        ->helperText('Required when closing or declining. Internal only.'),
                ])
                ->action(fn (Action $action, Enquiry $record, array $data) => DomainActions::run($action,
                    fn () => $service()->transition($record, EnquiryStatus::from($data['to']), $me(), $data['reason'] ?? null), 'Status updated')),

            Action::make('createQuotation')
                ->label('Prepare quotation')
                ->icon(Heroicon::OutlinedCalculator)
                ->visible(fn (Enquiry $record) => $record->client_id && $record->status->isOpen() && auth()->user()->can('create', Quotation::class))
                ->url(fn (Enquiry $record) => QuotationResource::getUrl('create', ['enquiry' => $record->id])),

            Action::make('createEngagement')
                ->label('Prepare engagement terms')
                ->icon(Heroicon::OutlinedDocumentCheck)
                ->visible(fn (Enquiry $record) => $record->client_id && $record->status->isOpen() && auth()->user()->can('create', Engagement::class))
                ->schema(fn (Enquiry $record) => [
                    TextInput::make('title')->required()->maxLength(190)->default($record->service?->name),
                    Select::make('template')->required()
                        ->options(fn () => EngagementTemplate::where('is_active', true)->orderBy('name')->pluck('name', 'id')),
                    Select::make('quotation_version')->label('Accepted quotation (optional)')
                        ->options(fn () => QuotationVersion::query()
                            ->whereIn('id', $record->quotations()->whereNotNull('accepted_version_id')->pluck('accepted_version_id'))
                            ->with('quotation')->get()
                            ->mapWithKeys(fn (QuotationVersion $v) => [$v->id => "{$v->quotation->reference} v{$v->version} – ".Money::format($v->total_minor, $v->quotation->currency)])),
                ])
                ->action(function (Action $action, Enquiry $record, array $data) use ($me) {
                    $engagement = DomainActions::run($action, fn () => app(Engagements::class)->create(
                        $record,
                        EngagementTemplate::findOrFail($data['template']),
                        $data['title'],
                        filled($data['quotation_version'] ?? null) ? QuotationVersion::findOrFail($data['quotation_version']) : null,
                        $me(),
                    ), 'Draft engagement terms created');
                    $action->redirect(EngagementResource::getUrl('view', ['record' => $engagement]));
                }),

            ActionGroup::make([
                Action::make('assign')
                    ->label('Assign owner')
                    ->icon(Heroicon::OutlinedUserCircle)
                    ->authorize('assign')
                    ->fillForm(fn (Enquiry $record) => ['owner' => $record->owner_id])
                    ->schema([Select::make('owner')->placeholder('Unassigned')->options(fn () => self::ownerOptions())])
                    ->action(fn (Action $action, Enquiry $record, array $data) => DomainActions::run($action,
                        fn () => $service()->assign($record, $data['owner'] ? User::findOrFail($data['owner']) : null, $me()), 'Owner updated')),

                Action::make('addParty')
                    ->label('Add party')
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->authorize('update')
                    ->visible(fn (Enquiry $record) => $record->status->isOpen())
                    ->modalDescription('Adding a party after a conflict decision resets the check to pending.')
                    ->schema([
                        TextInput::make('name')->required()->maxLength(190),
                        Select::make('role')->options(Party::ROLES)->required(),
                        Textarea::make('notes')->maxLength(1000)->rows(2),
                    ])
                    ->action(fn (Action $action, Enquiry $record, array $data) => DomainActions::run($action,
                        fn () => $service()->addParty($record, $data['name'], $data['role'], $data['notes'] ?? null, $me()), 'Party added')),

                Action::make('removeParty')
                    ->label('Remove party')
                    ->icon(Heroicon::OutlinedUserMinus)
                    ->authorize('update')
                    ->visible(fn (Enquiry $record) => $record->status->isOpen() && $record->conflict_status === 'pending' && $record->parties->isNotEmpty())
                    ->schema(fn (Enquiry $record) => [Select::make('party')->required()->options($record->parties->pluck('name', 'id'))])
                    ->action(fn (Action $action, Enquiry $record, array $data) => DomainActions::run($action,
                        fn () => $service()->removeParty($record->parties()->findOrFail($data['party']), $me()), 'Party removed')),

                Action::make('linkClient')
                    ->label('Link existing client')
                    ->icon(Heroicon::OutlinedLink)
                    ->authorize('update')
                    ->visible(fn (Enquiry $record) => $record->status->isOpen())
                    ->schema([
                        Select::make('client')->required()->searchable()
                            ->getSearchResultsUsing(fn (string $search) => self::clientSearch($search))
                            ->getOptionLabelUsing(fn ($value) => Client::find($value)?->display_name),
                    ])
                    ->action(fn (Action $action, Enquiry $record, array $data) => DomainActions::run($action,
                        fn () => $service()->linkClient($record, Client::query()->visibleTo($me())->findOrFail($data['client']), $me()), 'Client linked')),

                Action::make('createClient')
                    ->label('Create client record')
                    ->icon(Heroicon::OutlinedIdentification)
                    ->authorize('update')
                    ->visible(fn (Enquiry $record) => ! $record->client_id && $record->status->isOpen())
                    ->requiresConfirmation()
                    ->modalDescription('Creates a client record from the enquiry details. Portal access is separate: an administrator must invite the client.')
                    ->action(fn (Action $action, Enquiry $record) => DomainActions::run($action,
                        fn () => $service()->createClient($record, $me()), 'Client record created')),

                Action::make('followUp')
                    ->label('Set follow-up date')
                    ->icon(Heroicon::OutlinedCalendar)
                    ->authorize('update')
                    ->fillForm(fn (Enquiry $record) => ['date' => $record->follow_up_on?->toDateString()])
                    ->schema([DatePicker::make('date')->label('Follow up on')])
                    ->action(fn (Action $action, Enquiry $record, array $data) => DomainActions::run($action,
                        fn () => $service()->setFollowUp($record, $data['date'] ?? null, $me()), 'Follow-up updated')),

                Action::make('note')
                    ->label('Add internal note')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->authorize('update')
                    ->schema([Textarea::make('note')->required()->maxLength(5000)->rows(5)])
                    ->action(fn (Action $action, Enquiry $record, array $data) => DomainActions::run($action,
                        fn () => $service()->addNote($record, $data['note'], $me()), 'Note added')),
            ])->label('More')->icon(Heroicon::OutlinedEllipsisVertical)->button()->color('gray'),
        ];
    }

    /** @return array<int, string> clients the current user may see, by name or exact reference */
    public static function clientSearch(string $search): array
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

        return Client::query()->visibleTo(auth()->user())
            ->where(fn (Builder $query) => $query->where('display_name', 'like', $like)->orWhere('reference', $search))
            ->orderBy('display_name')->limit(20)->get()
            ->mapWithKeys(fn (Client $c) => [$c->id => "{$c->display_name} ({$c->reference})"])->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEnquiries::route('/'),
            'view' => ViewEnquiry::route('/{record}'),
        ];
    }
}
