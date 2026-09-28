<?php

namespace App\Filament\Resources\StaffApplications;

use App\Domain\Identity\Role;
use App\Domain\Recruitment\ApplicationStatus;
use App\Domain\Recruitment\StaffApplications;
use App\Filament\Resources\StaffApplications\Pages\ListStaffApplications;
use App\Filament\Resources\StaffApplications\Pages\ViewStaffApplication;
use App\Filament\Support\DomainActions;
use App\Http\Controllers\Site\CareersController;
use App\Models\StaffApplication;
use App\Models\StaffApplicationEvent;
use App\Models\StaffApplicationFile;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
use Illuminate\Support\Number;
use UnitEnum;

class StaffApplicationResource extends Resource
{
    protected static ?string $model = StaffApplication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Staff applications';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getNavigationBadge(): ?string
    {
        $open = StaffApplication::whereIn('status', [ApplicationStatus::Submitted, ApplicationStatus::UnderReview])->count();

        return $open ? (string) $open : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        $tz = config('app.firm_timezone');

        return $schema->components([
            Grid::make(3)->columnSpanFull()->schema([
                Section::make('Applicant')->columnSpan(2)->columns(2)->schema([
                    TextEntry::make('full_name'),
                    TextEntry::make('email')->copyable(),
                    TextEntry::make('phone'),
                    TextEntry::make('location'),
                    TextEntry::make('professional_category')->label('Category')
                        ->formatStateUsing(fn (string $state) => CareersController::CATEGORIES[$state] ?? $state),
                    TextEntry::make('years_experience')->label('Years of experience'),
                    TextEntry::make('practice_areas')->badge()->columnSpanFull()
                        ->formatStateUsing(fn (string $state) => CareersController::PRACTICE_AREAS[$state] ?? $state),
                    TextEntry::make('qualifications')->columnSpanFull(),
                    TextEntry::make('professional_registration')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('statement')->label('Statement')->placeholder('—')->columnSpanFull(),
                ]),
                Section::make('Status')->columnSpan(1)->schema([
                    TextEntry::make('reference')->copyable(),
                    TextEntry::make('status')->badge()
                        ->formatStateUsing(fn (ApplicationStatus $state) => $state->label())
                        ->color(fn (ApplicationStatus $state) => $state->color()),
                    TextEntry::make('assignedReviewer.name')->label('Reviewer')->placeholder('Unassigned'),
                    TextEntry::make('created_at')->label('Submitted')->dateTime(timezone: $tz),
                    TextEntry::make('decided_at')->label('Decided')->dateTime(timezone: $tz)->placeholder('—'),
                    TextEntry::make('approved_role')->label('Approved as')->placeholder('—')
                        ->formatStateUsing(fn (?string $state) => $state ? (Role::tryFrom($state)?->label() ?? $state) : null),
                    TextEntry::make('decision_reason')->placeholder('—'),
                    TextEntry::make('consent_version')->label('Consent')
                        ->formatStateUsing(fn (StaffApplication $record) => "Version {$record->consent_version}, ".$record->consent_at->timezone($tz)->format('j M Y H:i')),
                ]),
            ]),
            Section::make('Files')->columnSpanFull()->schema([
                RepeatableEntry::make('files')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('original_name')->label('File')
                        ->url(fn (StaffApplicationFile $record) => route('admin.application-file', $record))
                        ->color('primary')->icon(Heroicon::OutlinedArrowDownTray),
                    TextEntry::make('kind')->badge(),
                    TextEntry::make('size')->formatStateUsing(fn (int $state) => Number::fileSize($state)),
                    TextEntry::make('created_at')->label('Received')->dateTime(timezone: $tz),
                ]),
            ]),
            Section::make('History')->columnSpanFull()->schema([
                RepeatableEntry::make('events')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('created_at')->label('When')->dateTime(timezone: $tz),
                    TextEntry::make('type')->badge()
                        ->formatStateUsing(fn (StaffApplicationEvent $record) => str($record->type)->headline()
                            .($record->to_status ? ' → '.(ApplicationStatus::tryFrom($record->to_status)?->label() ?? $record->to_status) : '')),
                    TextEntry::make('actor.name')->label('By')->placeholder('Applicant / system'),
                    TextEntry::make('is_internal')->label('Visible to applicant')
                        ->formatStateUsing(fn (bool $state) => $state ? 'No (internal)' : 'Yes'),
                    TextEntry::make('body')->hiddenLabel()->placeholder('')->columnSpanFull(),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('assignedReviewer'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('reference')->searchable(),
                TextColumn::make('full_name')->label('Name')->searchable(),
                TextColumn::make('email')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('professional_category')->label('Category')
                    ->formatStateUsing(fn (string $state) => CareersController::CATEGORIES[$state] ?? $state),
                TextColumn::make('years_experience')->label('Years')->sortable(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (ApplicationStatus $state) => $state->label())
                    ->color(fn (ApplicationStatus $state) => $state->color()),
                TextColumn::make('assignedReviewer.name')->label('Reviewer')->placeholder('—'),
                TextColumn::make('created_at')->label('Submitted')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(ApplicationStatus::options())->multiple(),
                TernaryFilter::make('open')->label('Open applications')->default(true)
                    ->queries(
                        true: fn (Builder $query) => $query->whereIn('status', array_map(fn ($s) => $s->value, array_filter(ApplicationStatus::cases(), fn ($s) => $s->isOpen()))),
                        false: fn (Builder $query) => $query->whereNotIn('status', array_map(fn ($s) => $s->value, array_filter(ApplicationStatus::cases(), fn ($s) => $s->isOpen()))),
                    ),
                SelectFilter::make('professional_category')->label('Category')->options(CareersController::CATEGORIES),
            ])
            ->recordActions([ViewAction::make()]);
    }

    /** @return list<Action> review actions, each re-checked by StaffApplications */
    public static function reviewActions(): array
    {
        $service = fn (): StaffApplications => app(StaffApplications::class);
        $can = fn (StaffApplication $record, ApplicationStatus $to) => $record->status->canTransitionTo($to);

        return [
            Action::make('startReview')
                ->label('Start review')
                ->icon(Heroicon::OutlinedPlayCircle)
                ->authorize('review')
                ->visible(fn (StaffApplication $record) => $record->status === ApplicationStatus::Submitted)
                ->requiresConfirmation()
                ->action(fn (Action $action, StaffApplication $record) => DomainActions::run($action,
                    fn () => $service()->transition($record, ApplicationStatus::UnderReview, auth()->user()), 'Marked as under review')),

            Action::make('assign')
                ->label('Assign reviewer')
                ->icon(Heroicon::OutlinedUserCircle)
                ->authorize('review')
                ->visible(fn (StaffApplication $record) => $record->status->isOpen())
                ->fillForm(fn (StaffApplication $record) => ['reviewer' => $record->assigned_reviewer_id])
                ->schema([
                    Select::make('reviewer')->label('Reviewer')->placeholder('Unassigned')
                        ->options(fn () => User::active()->withActiveRole(...Role::fullAdministratorRoles())->orderBy('name')->pluck('name', 'id')),
                ])
                ->action(fn (Action $action, StaffApplication $record, array $data) => DomainActions::run($action,
                    fn () => $service()->assign($record, $data['reviewer'] ? User::findOrFail($data['reviewer']) : null, auth()->user()), 'Reviewer updated')),

            Action::make('note')
                ->label('Add internal note')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->authorize('review')
                ->schema([Textarea::make('note')->required()->maxLength(5000)->rows(5)])
                ->action(fn (Action $action, StaffApplication $record, array $data) => DomainActions::run($action,
                    fn () => $service()->addNote($record, $data['note'], auth()->user()), 'Note added')),

            Action::make('requestInfo')
                ->label('Request information')
                ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
                ->authorize('review')
                ->visible(fn (StaffApplication $record) => $can($record, ApplicationStatus::MoreInfoRequested))
                ->modalDescription('The applicant receives this message by email with a private link to reply and upload documents.')
                ->schema([Textarea::make('message')->required()->maxLength(5000)->rows(6)])
                ->action(fn (Action $action, StaffApplication $record, array $data) => DomainActions::run($action,
                    fn () => $service()->requestInformation($record, $data['message'], auth()->user()), 'Request sent to the applicant')),

            Action::make('approve')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->authorize('review')
                ->visible(fn (StaffApplication $record) => $can($record, ApplicationStatus::Approved))
                ->modalDescription('Approval sends the applicant a staff invitation for the role you choose. They set their own password and 2-step verification.')
                ->schema([
                    Select::make('role')->options(Role::options(staffOnly: true))->required()
                        ->helperText('Choose the least access they need; roles can be changed later.'),
                    Textarea::make('reason')->label('Decision note (internal)')->maxLength(2000),
                    DomainActions::currentPasswordField(),
                ])
                ->action(fn (Action $action, StaffApplication $record, array $data) => DomainActions::run($action,
                    fn () => $service()->approve($record, Role::from($data['role']), auth()->user(), $data['reason'] ?? null), 'Approved; invitation sent')),

            Action::make('decline')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->authorize('review')
                ->visible(fn (StaffApplication $record) => $can($record, ApplicationStatus::Declined))
                ->modalDescription('The applicant is told by email that the application was not successful. The reason below is internal and is not sent.')
                ->schema([Textarea::make('reason')->label('Reason (internal)')->required()->maxLength(2000)])
                ->action(fn (Action $action, StaffApplication $record, array $data) => DomainActions::run($action,
                    fn () => $service()->transition($record, ApplicationStatus::Declined, auth()->user(), $data['reason']), 'Application declined')),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaffApplications::route('/'),
            'view' => ViewStaffApplication::route('/{record}'),
        ];
    }
}
