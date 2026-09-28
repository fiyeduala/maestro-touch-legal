<?php

namespace App\Filament\Resources\Tags;

use App\Domain\Operations\Audit;
use App\Filament\Resources\Tags\Pages\ManageTags;
use App\Models\Tag;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use App\Support\SiteUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

class TagResource extends Resource
{
    protected static ?string $model = Tag::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Website';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(120)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Set $set, ?string $state, ?Tag $record) => $record ? null : $set('slug', Str::slug((string) $state))),
            TextInput::make('slug')->required()->maxLength(120)->regex(SiteUrl::SLUG_PATTERN)->validationMessages(['regex' => SiteUrl::SLUG_HELP])
                ->unique(ignoreRecord: true)
                ->helperText('Used in the address: /tag/slug/. Changing it breaks existing links unless you add a redirect.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('posts'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->searchable(),
                TextColumn::make('posts_count')->label('Posts')->sortable(),
            ])
            ->recordActions([
                EditAction::make()->after(fn (Tag $record) => Audit::record('tag.updated', "Updated tag {$record->name}", $record)),
                DeleteAction::make()->after(fn (Tag $record) => Audit::record('tag.deleted', "Deleted tag {$record->name}", $record)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTags::route('/'),
        ];
    }
}
