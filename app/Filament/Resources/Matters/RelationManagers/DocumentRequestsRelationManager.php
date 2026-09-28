<?php

namespace App\Filament\Resources\Matters\RelationManagers;

use App\Domain\Documents\Documents;
use App\Filament\Support\DomainActions;
use App\Models\DocumentRequest;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Documents the firm has asked the client to upload through the portal. */
class DocumentRequestsRelationManager extends MatterRelationManager
{
    protected static string $relationship = 'documentRequests';

    protected static ?string $title = 'Requests to the client';

    private function documents(): Documents
    {
        return app(Documents::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('requestedBy')->withCount('documents'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')->wrap()->description(fn (DocumentRequest $record) => $record->description),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->color(fn (string $state) => match ($state) { 'open' => 'warning', 'fulfilled' => 'success', default => 'gray' }),
                TextColumn::make('due_on')->label('Due')->date('j M Y')->placeholder('—'),
                TextColumn::make('documents_count')->label('Files received'),
                TextColumn::make('requestedBy.name')->label('Requested by')->placeholder('—'),
            ])
            ->headerActions([
                Action::make('request')
                    ->label('Request a document')
                    ->icon(Heroicon::OutlinedInboxArrowDown)
                    ->visible(fn () => $this->open() && $this->allowed('manageDocuments'))
                    ->modalDescription('The client sees this request in their portal and is notified.')
                    ->schema([
                        TextInput::make('title')->label('Document needed')->required()->maxLength(190),
                        Textarea::make('description')->label('Details for the client')->maxLength(2000)->rows(3),
                        DatePicker::make('due_on')->label('Needed by')->minDate(today()),
                    ])
                    ->action(fn (Action $action, array $data) => DomainActions::run($action,
                        fn () => $this->documents()->requestFromClient($this->matter(), $data['title'], $data['description'] ?? null, $data['due_on'] ?? null, $this->me()),
                        'Request sent to the client')),
            ])
            ->recordActions([
                Action::make('cancel')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->visible(fn (DocumentRequest $record) => $record->status === 'open' && $this->allowed('manageDocuments'))
                    ->schema([Textarea::make('reason')->label('Reason (internal)')->required()->minLength(5)->maxLength(2000)])
                    ->action(fn (Action $action, DocumentRequest $record, array $data) => DomainActions::run($action,
                        fn () => $this->documents()->cancelRequest($record, $data['reason'], $this->me()), 'Request cancelled')),
            ]);
    }
}
