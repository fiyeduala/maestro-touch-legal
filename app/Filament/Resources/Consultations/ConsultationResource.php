<?php

namespace App\Filament\Resources\Consultations;

use App\Domain\Clients\ClientContacts;
use App\Domain\Consultations\Consultations;
use App\Domain\Matters\MatterStatus;
use App\Domain\Meetings\VideoRooms;
use App\Filament\Resources\Consultations\Pages\ListConsultations;
use App\Filament\Resources\Consultations\Pages\ViewConsultation;
use App\Filament\Resources\Enquiries\EnquiryResource;
use App\Filament\Resources\Matters\MatterResource;
use App\Filament\Support\DomainActions;
use App\Models\Consultation;
use App\Models\ConsultationType;
use App\Models\Enquiry;
use App\Models\Matter;
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
use Filament\Forms\Components\Toggle;
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
 * Consultations for staff (spec §11). Full administrators see all; lawyers and case officers see those
 * they host, for enquiries they own, or on matters they are on (see ConsultationPolicy). Times are
 * entered and shown in the firm timezone. There are no per-lawyer calendars and no automatic meeting links.
 */
class ConsultationResource extends Resource
{
    protected static ?string $model = Consultation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Practice';

    protected static ?int $navigationSort = 4;

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
        $count = Consultation::visibleTo($user)->where('status', 'requested')->where('starts_at', '>', now())->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Waiting for confirmation';
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'requested' => 'warning',
            'confirmed' => 'success',
            'completed' => 'gray',
            default => 'danger',
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        $tz = config('app.firm_timezone');

        return $schema->components([
            Section::make()->columnSpanFull()->columns(3)->schema([
                TextEntry::make('status')->badge()->formatStateUsing(fn (Consultation $record) => $record->statusLabel())
                    ->color(fn (string $state) => self::statusColor($state)),
                TextEntry::make('starts_at')->label('When')->dateTime('D j M Y, g:i a', $tz)->suffix(' WAT'),
                TextEntry::make('type.name')->label('Type')
                    ->formatStateUsing(fn (Consultation $record) => "{$record->type->name} ({$record->type->duration_minutes} min, {$record->type->priceLabel()})"),
                TextEntry::make('contact_name')->label('With')
                    ->formatStateUsing(fn (Consultation $record) => "{$record->contact_name} <{$record->contact_email}>"),
                TextEntry::make('host.name')->label('Host')->placeholder('Not assigned yet'),
                TextEntry::make('meeting_url')->label('Meeting link')->placeholder('None yet')
                    ->state(fn (Consultation $record) => $record->video ? 'Video call on this website' : $record->meeting_url)
                    ->url(fn (Consultation $record) => $record->video ? null : $record->meeting_url, shouldOpenInNewTab: true),
                TextEntry::make('client.display_name')->label('Client')->placeholder('—'),
                TextEntry::make('enquiry.reference')->label('Enquiry')->placeholder('—')
                    ->url(fn (Consultation $record) => $record->enquiry && auth()->user()->can('view', $record->enquiry) ? EnquiryResource::getUrl('view', ['record' => $record->enquiry]) : null),
                TextEntry::make('matter.reference')->label('Matter')->placeholder('—')
                    ->url(fn (Consultation $record) => $record->matter && auth()->user()->can('view', $record->matter) ? MatterResource::getUrl('view', ['record' => $record->matter]) : null),
                TextEntry::make('client_agenda')->label('What the client wants to discuss')->placeholder('—')->columnSpanFull(),
                TextEntry::make('cancel_reason')->label('Cancellation reason')->visible(fn (Consultation $record) => $record->status === 'cancelled')->columnSpanFull(),
                TextEntry::make('outcome')->label('Outcome (internal)')->placeholder('—')->columnSpanFull()
                    ->visible(fn (Consultation $record) => in_array($record->status, ['completed', 'no_show'], true)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $tz = config('app.firm_timezone');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['type', 'host', 'client', 'matter:id,reference', 'enquiry:id,reference']))
            ->defaultSort('starts_at')
            ->columns([
                TextColumn::make('starts_at')->label('When (WAT)')->dateTime('D j M Y, g:i a', $tz)->sortable()
                    ->description(fn (Consultation $record) => $record->type->name),
                TextColumn::make('reference')->searchable(),
                TextColumn::make('contact_name')->label('With')->searchable()
                    ->description(fn (Consultation $record) => $record->matter?->reference ?? $record->enquiry?->reference),
                TextColumn::make('host.name')->label('Host')->placeholder('Unassigned'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (Consultation $record) => $record->statusLabel())
                    ->color(fn (string $state) => self::statusColor($state)),
            ])
            ->filters([
                TernaryFilter::make('upcoming')->label('Upcoming only')->default(true)
                    ->queries(
                        true: fn (Builder $query) => $query->active()->where('ends_at', '>', now()),
                        false: fn (Builder $query) => $query->where('starts_at', '<=', now()),
                        blank: fn (Builder $query) => $query,
                    ),
                SelectFilter::make('status')->options(Consultation::STATUSES),
                TernaryFilter::make('mine')->label('Hosted by me')
                    ->queries(true: fn (Builder $query) => $query->where('host_id', auth()->id()), false: fn (Builder $query) => $query, blank: fn (Builder $query) => $query),
            ])
            ->recordActions([ViewAction::make(), ...self::workActions()]);
    }

    /** @return list<Action|ActionGroup> */
    public static function workActions(): array
    {
        $service = fn (): Consultations => app(Consultations::class);
        $me = fn (): User => auth()->user();
        $tz = config('app.firm_timezone');
        $upcoming = fn (Consultation $record) => $record->isActive() && $record->starts_at->isFuture();

        return [
            Action::make('join')
                ->label('Join video call')
                ->icon(Heroicon::OutlinedVideoCamera)
                ->color('primary')
                ->authorize('update')
                ->visible(fn (Consultation $record) => $record->video && $record->status === 'confirmed' && VideoRooms::closes($record->ends_at)->isFuture())
                ->url(fn (Consultation $record) => route('meet.consultation', $record), shouldOpenInNewTab: true),
            Action::make('confirm')
                ->icon(Heroicon::OutlinedCheck)
                ->color('success')
                ->authorize('update')
                ->visible($upcoming)
                ->label(fn (Consultation $record) => $record->status === 'confirmed' ? 'Change host or link' : 'Confirm')
                ->modalDescription('The client is emailed the confirmation with a calendar invitation.')
                ->fillForm(fn (Consultation $record) => ['host_id' => $record->host_id ?? auth()->id(), 'video' => $record->video, 'meeting_url' => $record->meeting_url])
                ->schema(fn (Consultation $record) => [
                    Select::make('host_id')->label('Host')->required()
                        ->options(fn () => $service()->hosts()->pluck('name', 'id'))
                        ->disabled(fn () => ! auth()->user()->can('assignHost', $record) && $record->host_id !== null),
                    self::videoToggle(),
                    TextInput::make('meeting_url')->label('Meeting link (optional)')->maxLength(500)
                        ->regex('#^https://#i')->helperText('Paste a Zoom, Google Meet or Teams link, starting with https://. Leave empty for an in-person or phone consultation.')
                        ->hidden(fn (Get $get) => (bool) $get('video')),
                ])
                ->action(fn (Action $action, Consultation $record, array $data) => DomainActions::run($action,
                    fn () => $service()->confirm($record, isset($data['host_id']) ? (int) $data['host_id'] : null, $data['meeting_url'] ?? null, $me(), (bool) ($data['video'] ?? false)), 'Consultation confirmed')),
            ActionGroup::make([
                Action::make('reschedule')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->authorize('update')
                    ->visible($upcoming)
                    ->modalDescription('The client is emailed the new time. Staff can choose any free time, including outside the published hours.')
                    ->fillForm(fn (Consultation $record) => ['starts_at' => $record->starts_at])
                    ->schema([DateTimePicker::make('starts_at')->label('New time (WAT)')->required()->timezone($tz)->seconds(false)->minutesStep(5)])
                    ->action(fn (Action $action, Consultation $record, array $data) => DomainActions::run($action,
                        fn () => $service()->reschedule($record, Carbon::parse($data['starts_at'], config('app.timezone')), $me()), 'Consultation moved')),
                Action::make('cancel')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->authorize('update')
                    ->visible($upcoming)
                    ->modalDescription('The client is emailed that the consultation is cancelled, with the reason.')
                    ->schema([Textarea::make('reason')->label('Reason (sent to the client)')->required()->minLength(5)->maxLength(2000)])
                    ->action(fn (Action $action, Consultation $record, array $data) => DomainActions::run($action,
                        fn () => $service()->cancel($record, $data['reason'], $me()), 'Consultation cancelled')),
                Action::make('outcome')
                    ->label('Record outcome')
                    ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                    ->authorize('update')
                    ->visible(fn (Consultation $record) => $record->isActive() && $record->starts_at->isPast())
                    ->schema([
                        Radio::make('status')->label('What happened')->required()->options(['completed' => 'Held', 'no_show' => 'The client did not attend']),
                        Textarea::make('outcome')->label('Notes (internal, never shown to the client)')->maxLength(5000)->rows(4),
                    ])
                    ->action(fn (Action $action, Consultation $record, array $data) => DomainActions::run($action,
                        fn () => $service()->recordOutcome($record, $data['status'], $data['outcome'] ?? null, $me()), 'Outcome recorded')),
            ]),
        ];
    }

    /** Booking by staff, linked to an open enquiry or to a matter and one of its client contacts. */
    public static function scheduleAction(?Enquiry $enquiry = null, ?Matter $matter = null): Action
    {
        $tz = config('app.firm_timezone');
        $service = fn (): Consultations => app(Consultations::class);

        return Action::make('schedule')
            ->label('Book consultation')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->authorize('create', Consultation::class)
            ->modalDescription('Staff bookings are not limited to the published hours, but a time already full is refused. If confirmed now, the client is emailed straight away.')
            ->fillForm(fn () => [
                'link' => $matter ? 'matter' : 'enquiry',
                'enquiry_id' => $enquiry?->id,
                'matter_id' => $matter?->id,
                'type_id' => ConsultationType::where('is_active', true)->orderBy('sort')->value('id'),
                'host_id' => auth()->id(),
            ])
            ->schema([
                Radio::make('link')->label('For')->inline()->required()->live()
                    ->options(['enquiry' => 'An enquiry', 'matter' => 'A matter'])
                    ->hidden($enquiry !== null || $matter !== null),
                Select::make('enquiry_id')->label('Enquiry')->searchable()
                    ->options(fn () => Enquiry::visibleTo(auth()->user())
                        ->whereNotIn('status', ['converted', 'declined', 'closed'])
                        ->orderByDesc('id')->limit(200)->get()
                        ->mapWithKeys(fn (Enquiry $e) => [$e->id => "{$e->reference} · {$e->contact_name}"]))
                    ->visible(fn (Get $get) => $get('link') === 'enquiry')->required(fn (Get $get) => $get('link') === 'enquiry')
                    ->disabled($enquiry !== null)->dehydrated(),
                Select::make('matter_id')->label('Matter')->searchable()->live()
                    ->options(fn () => Matter::visibleTo(auth()->user())->where('status', '!=', MatterStatus::Closed->value)
                        ->orderByDesc('id')->limit(200)->get()
                        ->mapWithKeys(fn (Matter $m) => [$m->id => "{$m->reference} · {$m->title}"]))
                    ->visible(fn (Get $get) => $get('link') === 'matter')->required(fn (Get $get) => $get('link') === 'matter')
                    ->disabled($matter !== null)->dehydrated(),
                Select::make('contact_user_id')->label('Client contact')
                    ->options(function (Get $get) {
                        $picked = Matter::with('client')->find($get('matter_id'));

                        return $picked ? app(ClientContacts::class)->portalUsers($picked->client)->pluck('name', 'id') : [];
                    })
                    ->helperText('Only verified, active portal contacts of the client can be chosen.')
                    ->visible(fn (Get $get) => $get('link') === 'matter')->required(fn (Get $get) => $get('link') === 'matter'),
                Select::make('type_id')->label('Type')->required()
                    ->options(fn () => ConsultationType::where('is_active', true)->orderBy('sort')->get()
                        ->mapWithKeys(fn (ConsultationType $t) => [$t->id => "{$t->name} ({$t->duration_minutes} min, {$t->priceLabel()})"])),
                DateTimePicker::make('starts_at')->label('Time (WAT)')->required()->timezone($tz)->seconds(false)->minutesStep(5),
                Select::make('host_id')->label('Host')->options(fn () => $service()->hosts()->pluck('name', 'id')),
                self::videoToggle(),
                TextInput::make('meeting_url')->label('Meeting link (optional)')->maxLength(500)->regex('#^https://#i')
                    ->hidden(fn (Get $get) => (bool) $get('video')),
                Toggle::make('confirm')->label('Confirm now and email the client')->default(true),
            ])
            ->action(function (Action $action, array $data) use ($service, $enquiry, $matter) {
                $data['enquiry_id'] = $enquiry?->id ?? (($data['link'] ?? null) === 'enquiry' ? ($data['enquiry_id'] ?? null) : null);
                $data['matter_id'] = $matter?->id ?? (($data['link'] ?? null) === 'matter' ? ($data['matter_id'] ?? null) : null);
                $data['starts_at'] = Carbon::parse($data['starts_at'], config('app.timezone'));
                $booking = DomainActions::run($action, fn () => $service()->schedule($data, auth()->user()), 'Consultation booked');
                $action->redirect(self::getUrl('view', ['record' => $booking]));
            });
    }

    /** D50: a call on this website through Daily instead of a pasted Zoom/Meet/Teams link. */
    private static function videoToggle(): Toggle
    {
        return Toggle::make('video')->label('Video call on this website')->live()
            ->helperText('The client joins from their email or Client Area; contacts without an account get a private link. Not recorded.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConsultations::route('/'),
            'view' => ViewConsultation::route('/{record}'),
        ];
    }
}
