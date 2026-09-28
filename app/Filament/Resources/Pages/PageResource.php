<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Filament\Resources\Pages\RelationManagers\RevisionsRelationManager;
use App\Models\Page;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Saving never changes the live page: it creates a draft revision (PageRevisions::saveDraft)
 * that is previewed, then published by someone with the publish ability.
 */
class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Website';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'title';

    public static function status(Page $page): string
    {
        return match (true) {
            $page->published_revision_id && $page->draft_revision_id => 'Published, draft pending',
            (bool) $page->published_revision_id => 'Published',
            (bool) $page->draft_revision_id => 'Draft only',
            default => 'Unpublished',
        };
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Group::make(fn (?Page $record) => PageContentForm::for($record?->template ?? ''))
                ->statePath('content')->columnSpan(['lg' => 2]),
            Group::make([
                Section::make('Page')->schema([
                    TextInput::make('title')->required()->maxLength(255)
                        ->helperText('Used in the browser tab and search results.'),
                    TextInput::make('meta_title')->label('Search title (optional)')->maxLength(255),
                    Textarea::make('meta_description')->label('Search description')->rows(3)->maxLength(500),
                    Toggle::make('noindex')->label('Hide from search engines'),
                ]),
                Section::make('This save')->schema([
                    TextInput::make('note')->label('Note for the revision history')->maxLength(500)->dehydrated(),
                ]),
            ])->columnSpan(['lg' => 1]),
        ])->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->orderByRaw("path = '/' desc")->orderBy('path'))
            ->paginated(false)
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('path')->label('Address')
                    ->url(fn (Page $record) => $record->published_revision_id ? url($record->path) : null, shouldOpenInNewTab: true),
                TextColumn::make('template')->badge()->color('gray'),
                TextColumn::make('status')->state(fn (Page $record) => self::status($record))->badge()
                    ->color(fn (string $state) => match ($state) {
                        'Published' => 'success', 'Published, draft pending' => 'warning', default => 'gray',
                    }),
                TextColumn::make('updated_at')->label('Last change')->since(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [RevisionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPages::route('/'),
            'edit' => EditPage::route('/{record}/edit'),
        ];
    }
}
