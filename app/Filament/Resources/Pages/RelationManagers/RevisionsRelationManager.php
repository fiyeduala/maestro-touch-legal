<?php

namespace App\Filament\Resources\Pages\RelationManagers;

use App\Domain\Content\PageRevisions;
use App\Models\Page;
use App\Models\PageRevision;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RevisionsRelationManager extends RelationManager
{
    protected static string $relationship = 'revisions';

    protected static ?string $title = 'Revision history';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $tz = config('app.firm_timezone');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['author', 'publishedBy']))
            ->defaultSort('number', 'desc')
            ->columns([
                TextColumn::make('number')->label('Rev.')->prefix('r'),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'published' => 'success', 'draft' => 'warning', default => 'gray',
                    }),
                TextColumn::make('title'),
                TextColumn::make('note')->limit(60)->placeholder('—'),
                TextColumn::make('author.name')->label('Saved by')->placeholder('System'),
                TextColumn::make('created_at')->label('Saved')->dateTime('j M Y H:i', $tz),
                TextColumn::make('published_at')->label('Published')->dateTime('j M Y H:i', $tz)->placeholder('—'),
            ])
            ->recordActions([
                Action::make('preview')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->url(fn (PageRevision $record) => route('preview.page', ['page' => $record->page_id, 'revision' => $record]), shouldOpenInNewTab: true),
                Action::make('restore')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->visible(fn (PageRevision $record) => $record->status !== 'published' && auth()->user()->can('publish', $this->getOwnerRecord()))
                    ->requiresConfirmation()
                    ->modalDescription('Publishes a copy of this revision as the live page. Nothing is deleted from the history.')
                    ->action(function (PageRevision $record) {
                        /** @var Page $page */
                        $page = $this->getOwnerRecord();
                        abort_unless(auth()->user()->can('publish', $page), 403);
                        app(PageRevisions::class)->restore($record, auth()->user());
                        Notification::make()->success()->title("Restored r{$record->number}")->send();
                        $this->redirect(route('filament.admin.resources.pages.edit', $page));
                    }),
            ]);
    }
}
