<?php

namespace App\Filament\Resources\Engagements;

use App\Domain\Documents\UploadGuard;
use App\Domain\Engagement\Engagements;
use App\Domain\Engagement\OfferStatus;
use App\Domain\Identity\Role;
use App\Filament\Resources\Engagements\Pages\ListEngagements;
use App\Filament\Resources\Engagements\Pages\ViewEngagement;
use App\Filament\Resources\Enquiries\EnquiryResource;
use App\Filament\Resources\Matters\MatterResource;
use App\Filament\Support\DomainActions;
use App\Models\Engagement;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
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

class EngagementResource extends Resource
{
    protected static ?string $model = Engagement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Practice';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Engagement terms';

    protected static ?string $modelLabel = 'engagement terms';

    protected static ?string $pluralModelLabel = 'engagement terms';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    /** Accepted terms waiting for the internal approval that opens the matter. */
    public static function getNavigationBadge(): ?string
    {
        if (! auth()->user()->isFullAdministrator()) {
            return null;
        }
        $waiting = Engagement::where('status', OfferStatus::Accepted)->whereNull('matter_id')->count();

        return $waiting ? (string) $waiting : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        $tz = config('app.firm_timezone');

        return $schema->components([
            Grid::make(3)->columnSpanFull()->schema([
                Section::make(fn (Engagement $record) => 'Terms – version '.$record->currentVersion?->version)->columnSpan(2)->schema([
                    TextEntry::make('currentVersion.body')->hiddenLabel()->html()->prose(),
                ]),
                Section::make('Summary')->columnSpan(1)->schema([
                    TextEntry::make('reference')->copyable(),
                    TextEntry::make('title'),
                    TextEntry::make('status')->badge()
                        ->formatStateUsing(fn (OfferStatus $state) => $state->label())
                        ->color(fn (OfferStatus $state) => $state->color()),
                    TextEntry::make('client.display_name')->label('Client'),
                    TextEntry::make('enquiry.reference')->label('Enquiry')->placeholder('—')
                        ->url(fn (Engagement $record) => $record->enquiry && auth()->user()->can('view', $record->enquiry) ? EnquiryResource::getUrl('view', ['record' => $record->enquiry]) : null),
                    TextEntry::make('enquiry.conflict_status')->label('Conflict check')->badge()
                        ->formatStateUsing(fn (string $state) => ucfirst($state))
                        ->color(fn (string $state) => EnquiryResource::conflictColor($state)),
                    TextEntry::make('matter.reference')->label('Matter')->placeholder('Not opened')
                        ->url(fn (Engagement $record) => $record->matter ? MatterResource::getUrl('view', ['record' => $record->matter]) : null),
                    TextEntry::make('currentVersion.sent_at')->label('Sent')->dateTime(timezone: $tz)->placeholder('Not sent'),
                    TextEntry::make('currentVersion.content_hash')->label('Content fingerprint (SHA-256)')->placeholder('Set when sent')
                        ->fontFamily('mono')->size('xs')->copyable(),
                    TextEntry::make('approvedBy.name')->label('Approved by')->placeholder('—'),
                    TextEntry::make('approved_at')->dateTime(timezone: $tz)->placeholder('—'),
                    TextEntry::make('approval_note')->placeholder('—')->visible(fn (Engagement $record) => filled($record->approval_note)),
                ]),
            ]),
            Section::make('Client responses')->columnSpanFull()
                ->visible(fn (Engagement $record) => $record->versions->flatMap->acceptances->isNotEmpty())
                ->schema([
                    RepeatableEntry::make('responses')->hiddenLabel()->columns(4)
                        ->state(fn (Engagement $record) => $record->versions->flatMap(fn ($v) => $v->acceptances->map(fn ($a) => [
                            'version' => $v->version,
                            'decision' => $a->decision,
                            'method' => $a->method === 'offline_signed' ? 'Signed copy recorded by '.($a->recordedBy?->name ?? 'staff') : 'Portal',
                            'signed_name' => $a->signed_name,
                            'when' => $a->created_at,
                            'comment' => $a->comment,
                            'evidence' => $a->evidenceDocument?->current_version_id,
                        ]))->values()->all())
                        ->schema([
                            TextEntry::make('version')->prefix('v'),
                            TextEntry::make('decision')->badge(),
                            TextEntry::make('method'),
                            TextEntry::make('when')->dateTime(timezone: $tz),
                            TextEntry::make('signed_name')->label('Typed / signed name')->placeholder('—'),
                            TextEntry::make('evidence')->label('Evidence')->placeholder('—')
                                ->formatStateUsing(fn () => 'Download signed copy')
                                ->url(fn (?int $state) => $state ? route('admin.document-file', $state) : null)->color('primary'),
                            TextEntry::make('comment')->placeholder('')->columnSpan(2),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['client']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('reference')->searchable(),
                TextColumn::make('title')->searchable()->limit(40),
                TextColumn::make('client.display_name')->label('Client')->searchable(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (OfferStatus $state) => $state->label())
                    ->color(fn (OfferStatus $state) => $state->color()),
                TextColumn::make('updated_at')->label('Updated')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(OfferStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all())->multiple(),
            ])
            ->recordActions([ViewAction::make()]);
    }

    /** @return list<Action> each re-checked by Engagements */
    public static function workActions(): array
    {
        $service = fn (): Engagements => app(Engagements::class);
        $me = fn (): User => auth()->user();

        return [
            Action::make('approve')
                ->label('Approve and open matter')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->authorize('approve')
                ->modalDescription('Internal approval of the accepted terms. This opens the matter and starts representation (payment is not a condition). The conflict check is re-verified first.')
                ->schema([
                    Select::make('responsible')->label('Responsible lawyer')->required()
                        ->options(fn () => User::active()->withActiveRole(Role::Lawyer)->orderBy('name')->pluck('name', 'id')),
                    Textarea::make('note')->label('Approval note (internal)')->maxLength(2000),
                    DomainActions::currentPasswordField(),
                ])
                ->action(function (Action $action, Engagement $record, array $data) use ($service, $me) {
                    $matter = DomainActions::run($action, fn () => $service()->approve($record, User::findOrFail($data['responsible']), $data['note'] ?? null, $me()), 'Approved; matter opened');
                    $action->redirect(MatterResource::getUrl('view', ['record' => $matter]));
                }),

            Action::make('send')
                ->label('Send to client')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->authorize('update')
                ->visible(fn (Engagement $record) => $record->status === OfferStatus::Draft)
                ->requiresConfirmation()
                ->modalDescription('Freezes this version and notifies the client\'s portal contacts. The conflict check must be cleared.')
                ->action(fn (Action $action, Engagement $record) => DomainActions::run($action,
                    fn () => $service()->send($record, $me()), 'Engagement terms sent')),

            Action::make('revise')
                ->label(fn (Engagement $record) => $record->status === OfferStatus::Draft ? 'Edit draft' : 'Revise (new version)')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->authorize('update')
                ->modalWidth('5xl')
                ->modalDescription(fn (Engagement $record) => $record->status === OfferStatus::Draft ? null
                    : 'The sent version stays on record; the client must accept the new version.')
                ->fillForm(fn (Engagement $record) => ['title' => $record->title, 'body' => $record->currentVersion?->body])
                ->schema([
                    TextInput::make('title')->required()->maxLength(190),
                    RichEditor::make('body')->label('Terms')->required()
                        ->toolbarButtons([['bold', 'italic', 'underline', 'link'], ['h2', 'h3'], ['bulletList', 'orderedList', 'blockquote'], ['undo', 'redo']]),
                ])
                ->action(fn (Action $action, Engagement $record, array $data) => DomainActions::run($action,
                    fn () => $service()->revise($record, $data['title'], $data['body'], $me()), 'Engagement terms saved')),

            Action::make('offline')
                ->label('Record signed copy')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->authorize('recordOfflineAcceptance')
                ->modalDescription('Use when the client signed and returned the sent version outside the portal. You record it with the signed copy as evidence; it is never recorded as the client\'s own portal acceptance.')
                ->schema([
                    FileUpload::make('evidence')->label('Signed copy')->required()->storeFiles(false)
                        ->acceptedFileTypes(array_values(array_unique(UploadGuard::TYPES)))->maxSize(UploadGuard::MAX_KILOBYTES)
                        ->helperText('PDF, DOCX, JPG, PNG or WebP, up to 20 MB. Stored privately.'),
                    Grid::make(2)->schema([
                        TextInput::make('signed_name')->label('Name of the person who signed')->required()->minLength(3)->maxLength(150),
                        DatePicker::make('signed_on')->label('Date signed')->required()->maxDate(now()),
                    ]),
                    Textarea::make('note')->maxLength(2000),
                ])
                ->action(fn (Action $action, Engagement $record, array $data) => DomainActions::run($action,
                    fn () => $service()->recordOfflineAcceptance($record, $record->current_version_id, $data['evidence'], $data['signed_name'], $data['signed_on'], $data['note'] ?? null, $me()),
                    'Signed copy recorded')),

            Action::make('withdraw')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->authorize('update')
                ->visible(fn (Engagement $record) => $record->status === OfferStatus::Sent)
                ->schema([Textarea::make('reason')->label('Reason (internal)')->required()->maxLength(2000)])
                ->action(fn (Action $action, Engagement $record, array $data) => DomainActions::run($action,
                    fn () => $service()->withdraw($record, $data['reason'], $me()), 'Engagement terms withdrawn')),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEngagements::route('/'),
            'view' => ViewEngagement::route('/{record}'),
        ];
    }
}
