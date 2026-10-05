<?php

namespace App\Filament\Resources\Meetings;

use App\Domain\Matters\MatterStatus;
use App\Domain\Meetings\Meetings;
use App\Domain\Meetings\VideoRooms;
use App\Filament\Resources\Matters\MatterResource;
use App\Filament\Resources\Meetings\Pages\ListMeetings;
use App\Filament\Resources\Meetings\Pages\ViewMeeting;
use App\Filament\Support\DomainActions;
use App\Models\Client;
use App\Models\Matter;
use App\Models\Meeting;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Video meetings (D50). Full administrators schedule with anyone; lawyers and case officers with colleagues
 * and with the client contacts of matters they are on. Everyone invited is emailed, notified in the bell and
 * by push, and reminded shortly before. The call runs on Daily.co; it is private and not recorded.
 */
class MeetingResource extends Resource
{
    protected static ?string $model = Meeting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedVideoCamera;

    protected static string|UnitEnum|null $navigationGroup = 'Practice';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();
        if (! $user) {
            return null;
        }
        $count = Meeting::where('status', 'scheduled')->whereBetween('starts_at', [now(), now()->endOfDay()])
            ->whereHas('participants', fn (Builder $q) => $q->whereKey($user->id))->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Your meetings later today';
    }

    public static function statusColor(Meeting $meeting): string
    {
        return match ($meeting->statusLabel()) {
            'Scheduled' => 'success',
            'Cancelled' => 'danger',
            default => 'gray',
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        $tz = config('app.firm_timezone');

        return $schema->components([
            Section::make()->columnSpanFull()->columns(3)->schema([
                TextEntry::make('status')->badge()->formatStateUsing(fn (Meeting $record) => $record->statusLabel())
                    ->color(fn (Meeting $record) => self::statusColor($record)),
                TextEntry::make('starts_at')->label('When')->dateTime('D j M Y, g:i a', $tz)->suffix(' WAT'),
                TextEntry::make('ends_at')->label('Length')->formatStateUsing(fn (Meeting $record) => $record->starts_at->diffInMinutes($record->ends_at).' minutes'),
                TextEntry::make('organiser.name')->label('Organiser'),
                TextEntry::make('matter.reference')->label('Matter')->placeholder('—')
                    ->url(fn (Meeting $record) => $record->matter && auth()->user()->can('view', $record->matter) ? MatterResource::getUrl('view', ['record' => $record->matter]) : null),
                TextEntry::make('client.display_name')->label('Client')->placeholder('—'),
                TextEntry::make('participants')->label('Invited')->columnSpanFull()
                    ->state(fn (Meeting $record) => $record->participants->map(fn (User $u) => $u->name.($u->isStaff() ? '' : ' (client)'))->implode(', ')),
                TextEntry::make('agenda')->placeholder('—')->columnSpanFull(),
                TextEntry::make('cancel_reason')->label('Cancellation reason')->placeholder('—')->columnSpanFull()
                    ->visible(fn (Meeting $record) => $record->status === 'cancelled'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $tz = config('app.firm_timezone');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['organiser:id,name', 'matter:id,reference', 'client:id,display_name'])->withCount('participants'))
            ->defaultSort('starts_at')
            ->columns([
                TextColumn::make('starts_at')->label('When (WAT)')->dateTime('D j M Y, g:i a', $tz)->sortable()
                    ->description(fn (Meeting $record) => $record->starts_at->diffInMinutes($record->ends_at).' min'),
                TextColumn::make('title')->searchable()->limit(60)->description(fn (Meeting $record) => $record->reference),
                TextColumn::make('matter.reference')->label('Matter / client')->placeholder('Internal')
                    ->description(fn (Meeting $record) => $record->client?->display_name),
                TextColumn::make('organiser.name')->label('Organiser'),
                TextColumn::make('participants_count')->label('People'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (Meeting $record) => $record->statusLabel())
                    ->color(fn (Meeting $record) => self::statusColor($record)),
            ])
            ->filters([
                TernaryFilter::make('upcoming')->label('Upcoming only')->default(true)
                    ->queries(
                        true: fn (Builder $query) => $query->where('status', 'scheduled')->where('ends_at', '>', now()),
                        false: fn (Builder $query) => $query->where('ends_at', '<=', now()),
                        blank: fn (Builder $query) => $query,
                    ),
                SelectFilter::make('status')->options(Meeting::STATUSES),
                TernaryFilter::make('mine')->label('I am invited')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('participants', fn (Builder $q) => $q->whereKey(auth()->id())),
                        false: fn (Builder $query) => $query,
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->recordActions([ViewAction::make(), ...self::workActions()]);
    }

    /** @return list<Action|ActionGroup> */
    public static function workActions(): array
    {
        $service = fn (): Meetings => app(Meetings::class);
        $me = fn (): User => auth()->user();
        $tz = config('app.firm_timezone');
        $open = fn (Meeting $record) => $record->isScheduled() && $record->ends_at->isFuture();

        return [
            Action::make('join')
                ->label('Join call')
                ->icon(Heroicon::OutlinedVideoCamera)
                ->color('primary')
                ->authorize('join')
                ->visible(fn (Meeting $record) => $record->isScheduled() && VideoRooms::closes($record->ends_at)->isFuture())
                ->url(fn (Meeting $record) => route('meet.meeting', $record), shouldOpenInNewTab: true),
            ActionGroup::make([
                Action::make('reschedule')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->authorize('update')
                    ->visible($open)
                    ->modalDescription('Everyone invited is told the new time, with an updated calendar invitation.')
                    ->fillForm(fn (Meeting $record) => ['starts_at' => $record->starts_at, 'duration_minutes' => $record->starts_at->diffInMinutes($record->ends_at)])
                    ->schema([
                        DateTimePicker::make('starts_at')->label('New time (WAT)')->required()->timezone($tz)->seconds(false)->minutesStep(5),
                        self::durationField(),
                    ])
                    ->action(fn (Action $action, Meeting $record, array $data) => DomainActions::run($action,
                        fn () => $service()->reschedule($record, Carbon::parse($data['starts_at'], config('app.timezone')), (int) $data['duration_minutes'], $me()), 'Meeting moved')),
                Action::make('invite')
                    ->label('Invite more people')
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->authorize('update')
                    ->visible($open)
                    ->schema(fn (Meeting $record) => [
                        Select::make('staff_ids')->label('Colleagues')->multiple()->searchable()
                            ->options(fn () => $service()->staff()->reject(fn (User $u) => $record->hasParticipant($u))->pluck('name', 'id')),
                        Select::make('client_user_ids')->label('Client contacts')->multiple()
                            ->options(fn () => $service()->clientContacts($record->client)->pluck('name', 'id'))
                            ->visible($record->client_id !== null),
                    ])
                    ->action(fn (Action $action, Meeting $record, array $data) => DomainActions::run($action,
                        fn () => $service()->invite($record, $data, $me()), 'Invitations sent')),
                Action::make('cancel')
                    ->label('Cancel meeting')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->authorize('update')
                    ->visible($open)
                    ->modalDescription('Everyone invited is told the meeting is cancelled, with the reason if you give one.')
                    ->schema([Textarea::make('reason')->label('Reason (sent to everyone invited)')->maxLength(2000)])
                    ->action(fn (Action $action, Meeting $record, array $data) => DomainActions::run($action,
                        fn () => $service()->cancel($record, $data['reason'] ?? null, $me()), 'Meeting cancelled')),
            ]),
        ];
    }

    /** Schedule a meeting; from a matter page the matter is fixed. */
    public static function scheduleAction(?Matter $matter = null): Action
    {
        $tz = config('app.firm_timezone');
        $service = fn (): Meetings => app(Meetings::class);
        $clientFor = function (Get $get) use ($matter): ?Client {
            if ($matter) {
                return $matter->client;
            }

            return match ($get('link')) {
                'matter' => Matter::with('client')->find($get('matter_id'))?->client,
                'client' => Client::find($get('client_id')),
                default => null,
            };
        };

        return Action::make('scheduleMeeting')
            ->label('Schedule video meeting')
            ->icon(Heroicon::OutlinedVideoCamera)
            ->authorize('create', Meeting::class)
            ->modalDescription('Everyone invited is emailed a calendar invitation and notified here and on their devices. The call opens '.(int) config('video.join_early_minutes', 15).' minutes before the start. It is private and not recorded.')
            ->fillForm(fn () => ['link' => $matter ? 'matter' : 'none', 'matter_id' => $matter?->id, 'duration_minutes' => 30])
            ->schema([
                TextInput::make('title')->required()->minLength(3)->maxLength(200)
                    ->helperText('Staff see the title. Client contacts are only told it is a video meeting with the firm.'),
                DateTimePicker::make('starts_at')->label('Time (WAT)')->required()->timezone($tz)->seconds(false)->minutesStep(5),
                self::durationField(),
                Radio::make('link')->label('Linked to')->inline()->required()->live()
                    ->options(fn () => array_filter([
                        'none' => 'Staff only',
                        'matter' => 'A matter',
                        'client' => auth()->user()->isFullAdministrator() ? 'A client' : null,
                    ]))
                    ->hidden($matter !== null),
                Select::make('matter_id')->label('Matter')->searchable()->live()
                    ->options(fn () => Matter::visibleTo(auth()->user())->where('status', '!=', MatterStatus::Closed->value)
                        ->orderByDesc('id')->limit(200)->get()->mapWithKeys(fn (Matter $m) => [$m->id => "{$m->reference} · {$m->title}"]))
                    ->visible(fn (Get $get) => $matter === null && $get('link') === 'matter')->required(fn (Get $get) => $matter === null && $get('link') === 'matter'),
                Select::make('client_id')->label('Client')->searchable()->live()
                    ->options(fn () => Client::orderBy('display_name')->limit(500)->pluck('display_name', 'id'))
                    ->visible(fn (Get $get) => $get('link') === 'client')->required(fn (Get $get) => $get('link') === 'client'),
                Select::make('client_user_ids')->label('Client contacts')->multiple()
                    ->options(fn (Get $get) => $service()->clientContacts($clientFor($get))->pluck('name', 'id'))
                    ->helperText('Only verified, active portal contacts of the client can be invited.')
                    ->visible(fn (Get $get) => $matter !== null || in_array($get('link'), ['matter', 'client'], true)),
                Select::make('staff_ids')->label('Colleagues')->multiple()->searchable()
                    ->options(fn () => $service()->staff()->reject(fn (User $u) => $u->id === auth()->id())->pluck('name', 'id')),
                Textarea::make('agenda')->label('Agenda (staff only)')->maxLength(5000)->rows(3),
            ])
            ->action(function (Action $action, array $data) use ($service, $matter) {
                $link = $matter ? 'matter' : ($data['link'] ?? 'none');
                $booking = DomainActions::run($action, fn () => $service()->schedule([
                    'title' => $data['title'],
                    'agenda' => $data['agenda'] ?? null,
                    'starts_at' => Carbon::parse($data['starts_at'], config('app.timezone')),
                    'duration_minutes' => (int) $data['duration_minutes'],
                    'matter_id' => $matter?->id ?? ($link === 'matter' ? ($data['matter_id'] ?? null) : null),
                    'client_id' => $link === 'client' ? ($data['client_id'] ?? null) : null,
                    'staff_ids' => $data['staff_ids'] ?? [],
                    'client_user_ids' => $link === 'none' ? [] : ($data['client_user_ids'] ?? []),
                ], auth()->user()), 'Meeting scheduled; invitations sent');
                $action->redirect(self::getUrl('view', ['record' => $booking]));
            });
    }

    private static function durationField(): Select
    {
        return Select::make('duration_minutes')->label('Length')->required()
            ->options([15 => '15 minutes', 30 => '30 minutes', 45 => '45 minutes', 60 => '1 hour', 90 => '1½ hours', 120 => '2 hours', 180 => '3 hours']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMeetings::route('/'),
            'view' => ViewMeeting::route('/{record}'),
        ];
    }
}
