<?php

namespace App\Filament\Resources\Matters\RelationManagers;

use App\Domain\Documents\Documents;
use App\Domain\Documents\DocumentStatus;
use App\Domain\Documents\UploadGuard;
use App\Filament\Support\DomainActions;
use App\Models\Document;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Matter documents. Deliverables go draft → review → approved → released; the client only ever
 * sees the released version (or their own uploads). No virus scanning is configured.
 */
class DocumentsRelationManager extends MatterRelationManager
{
    protected static string $relationship = 'documents';

    private function documents(): Documents
    {
        return app(Documents::class);
    }

    private static function fileField(): FileUpload
    {
        return FileUpload::make('file')->required()->storeFiles(false)
            ->acceptedFileTypes(array_values(array_unique(UploadGuard::TYPES)))
            ->maxSize(UploadGuard::MAX_KILOBYTES)
            ->helperText('PDF, Word (.docx), images or text, up to 20 MB. Files are not virus-scanned.');
    }

    public function table(Table $table): Table
    {
        $tz = config('app.firm_timezone');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['matter', 'currentVersion', 'releasedVersion', 'request']))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('title')->searchable()->wrap()
                    ->description(fn (Document $record) => $record->request ? "For request: {$record->request->title}" : null),
                TextColumn::make('category')->formatStateUsing(fn (string $state) => Document::CATEGORIES[$state] ?? $state),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (DocumentStatus $state) => $state->label())
                    ->color(fn (DocumentStatus $state) => $state->color()),
                TextColumn::make('currentVersion.version')->label('Version')->prefix('v')
                    ->url(fn (Document $record) => $record->current_version_id ? route('admin.document-file', $record->current_version_id) : null, true),
                TextColumn::make('releasedVersion.version')->label('Client sees')->prefix('v')
                    ->placeholder(fn (Document $record) => $record->uploaded_by_client ? 'Client upload' : 'Not shared'),
                TextColumn::make('client_decision')->label('Client response')->placeholder('—')
                    ->formatStateUsing(fn (?string $state) => match ($state) { 'approved' => 'Approved', 'changes_requested' => 'Changes requested', default => $state }),
                IconColumn::make('uploaded_by_client')->label('From client')->boolean(),
                TextColumn::make('updated_at')->label('Updated')->dateTime('j M Y H:i', $tz)->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')->options(Document::CATEGORIES),
                SelectFilter::make('status')->options(collect(DocumentStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()),
            ])
            ->headerActions([
                Action::make('upload')
                    ->label('Upload document')
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->visible(fn () => $this->open() && $this->allowed('manageDocuments'))
                    ->schema([
                        self::fileField(),
                        TextInput::make('title')->required()->maxLength(190),
                        Toggle::make('is_deliverable')->label('This is a draft deliverable for the client')->live()
                            ->helperText('Deliverables need a lawyer\'s approval before they can be shared.'),
                        Select::make('category')->options(Document::CATEGORIES)->required()
                            ->hidden(fn (Get $get) => (bool) $get('is_deliverable')),
                        Textarea::make('note')->label('Version note (internal)')->maxLength(1000)->rows(2),
                    ])
                    ->action(fn (Action $action, array $data) => DomainActions::run($action,
                        fn () => $this->documents()->upload($this->matter(), $data['file'], [
                            'title' => $data['title'],
                            'category' => $data['category'] ?? 'deliverable',
                            'is_deliverable' => (bool) ($data['is_deliverable'] ?? false),
                            'note' => $data['note'] ?? null,
                        ], $this->me()), 'Document uploaded')),
            ])
            ->recordActions([
                Action::make('submit')
                    ->label('Send for review')
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->authorize('update')
                    ->visible(fn (Document $record) => $record->is_deliverable && in_array($record->status, [DocumentStatus::Draft, DocumentStatus::ChangesRequested], true))
                    ->requiresConfirmation()
                    ->action(fn (Action $action, Document $record) => DomainActions::run($action,
                        fn () => $this->documents()->submitForReview($record, $this->me()), 'Sent for review')),
                Action::make('approve')
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->color('success')
                    ->authorize('approve')
                    ->visible(fn (Document $record) => $record->status === DocumentStatus::InReview)
                    ->schema([Textarea::make('note')->label('Note (internal)')->maxLength(1000)])
                    ->action(fn (Action $action, Document $record, array $data) => DomainActions::run($action,
                        fn () => $this->documents()->approve($record, $data['note'] ?? null, $this->me()), 'Approved')),
                Action::make('release')
                    ->label('Share with client')
                    ->icon(Heroicon::OutlinedShare)
                    ->color('primary')
                    ->authorize('update')
                    ->visible(fn (Document $record) => ! $record->uploaded_by_client
                        && ($record->is_deliverable ? $record->status === DocumentStatus::Approved : $record->status === DocumentStatus::Filed))
                    ->modalDescription('The client will see this version in their portal and be notified.')
                    ->schema([Textarea::make('note')->label('Message shown with the document (optional)')->maxLength(1000)])
                    ->action(fn (Action $action, Document $record, array $data) => DomainActions::run($action,
                        fn () => $this->documents()->release($record, $data['note'] ?? null, $this->me()), 'Shared with the client')),
                ActionGroup::make([
                    Action::make('requestChanges')
                        ->label('Request changes')
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->authorize('approve')
                        ->visible(fn (Document $record) => $record->status === DocumentStatus::InReview)
                        ->schema([Textarea::make('note')->label('What needs to change (internal)')->required()->minLength(5)->maxLength(2000)])
                        ->action(fn (Action $action, Document $record, array $data) => DomainActions::run($action,
                            fn () => $this->documents()->requestChanges($record, $data['note'], $this->me()), 'Sent back to draft')),
                    Action::make('newVersion')
                        ->label('Upload new version')
                        ->icon(Heroicon::OutlinedDocumentPlus)
                        ->authorize('update')
                        ->visible(fn (Document $record) => $this->open() && ! $record->uploaded_by_client)
                        ->modalDescription(fn (Document $record) => $record->is_deliverable
                            ? 'The draft returns to review. The client keeps seeing the version already shared until you share again.'
                            : null)
                        ->schema([self::fileField(), Textarea::make('note')->label('Version note (internal)')->maxLength(1000)])
                        ->action(fn (Action $action, Document $record, array $data) => DomainActions::run($action,
                            fn () => $this->documents()->addVersion($record, $data['file'], $data['note'] ?? null, $this->me()), 'New version uploaded')),
                    Action::make('versions')
                        ->label('Version history')
                        ->icon(Heroicon::OutlinedClock)
                        ->authorize('view')
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close')
                        ->modalContent(fn (Document $record) => view('filament.document-history', [
                            'document' => $record->load(['versions.uploadedBy', 'events.actor']),
                        ])),
                    Action::make('withdraw')
                        ->label('Stop sharing')
                        ->icon(Heroicon::OutlinedEyeSlash)
                        ->color('danger')
                        ->authorize('update')
                        ->visible(fn (Document $record) => $record->released_version_id && ! $record->uploaded_by_client)
                        ->modalDescription('The client loses access from now on. Copies they already downloaded cannot be recalled.')
                        ->schema([Textarea::make('reason')->label('Reason (internal)')->required()->minLength(5)->maxLength(2000)])
                        ->action(fn (Action $action, Document $record, array $data) => DomainActions::run($action,
                            fn () => $this->documents()->withdrawFromClient($record, $data['reason'], $this->me()), 'No longer shared')),
                ]),
            ]);
    }
}
