<?php

namespace App\Filament\Resources\Posts;

use App\Domain\Operations\Audit;
use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Models\Page;
use App\Models\Post;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use App\Support\SiteUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static string|UnitEnum|null $navigationGroup = 'Website';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Blog posts';

    protected static ?string $modelLabel = 'blog post';

    protected static ?string $recordTitleAttribute = 'title';

    /** Top-level paths the application owns; a post slug can never take one of them. */
    public const RESERVED_SLUGS = [
        'admin', 'portal', 'preview', 'livewire', 'filament', 'up', 'storage', 'build', 'media', 'images', 'wp-content',
        'blog', 'category', 'tag', 'feed', 'sitemap', 'robots', 'invitation', 'careers', 'join-our-legal-team',
        'log-in', 'logout', 'register', 'password-reset', 'email', 'profile', 'search', 'page',
    ];

    /** @return array<string, string> statuses the current user may choose */
    public static function statusOptions(): array
    {
        return auth()->user()?->can('publish', Post::class)
            ? Post::STATUSES
            : array_intersect_key(Post::STATUSES, array_flip(['draft', 'review', 'archived']));
    }

    public static function form(Schema $schema): Schema
    {
        $tz = config('app.firm_timezone');

        return $schema->columns(3)->components([
            Group::make([
                Section::make()->schema([
                    TextInput::make('title')->required()->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get, ?string $state, ?Post $record) => $record ? null : $set('slug', Str::slug((string) $state))),
                    TextInput::make('slug')->required()->maxLength(190)->regex(SiteUrl::SLUG_PATTERN)->validationMessages(['regex' => SiteUrl::SLUG_HELP])
                        ->prefix(url('/').'/')->suffix('/')
                        ->unique(ignoreRecord: true)
                        ->notIn(self::RESERVED_SLUGS)
                        ->rules([fn (): Closure => function (string $attribute, $value, Closure $fail) {
                            if (Page::where('slug', $value)->exists()) {
                                $fail('A website page already uses this address.');
                            }
                        }])
                        ->helperText('Changing the address of a live post adds a redirect from the old one automatically.'),
                    Textarea::make('excerpt')->rows(3)->maxLength(1000)
                        ->helperText('Shown on the blog list. Leave empty to use the start of the article.'),
                    RichEditor::make('body')->required()
                        ->fileAttachmentsDisk('media')
                        ->fileAttachmentsDirectory('blog/'.now()->format('Y/m'))
                        ->fileAttachmentsVisibility('public')
                        ->fileAttachmentsAcceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                        ->fileAttachmentsMaxSize(5120)
                        ->extraInputAttributes(['style' => 'min-height: 24rem']),
                ]),
            ])->columnSpan(['lg' => 2]),
            Group::make([
                Section::make('Publishing')->schema([
                    Select::make('status')->options(fn () => self::statusOptions())->required()->default('draft')->live()
                        ->helperText(fn () => auth()->user()?->can('publish', Post::class)
                            ? null : 'You can save drafts and submit them for review. An administrator publishes them.'),
                    DateTimePicker::make('published_at')->label('Publish date')->timezone($tz)->seconds(false)
                        ->required(fn (Get $get) => $get('status') === 'scheduled')
                        ->helperText('Leave empty to use the moment it is published. A future date with “Scheduled” publishes it then.'),
                    TextInput::make('author_name')->label('Author shown on the post')->maxLength(255),
                    Toggle::make('comments_visible')->label('Show approved comments')->default(true),
                ]),
                Section::make('Organisation')->schema([
                    Select::make('cover_media_id')->label('Cover image')
                        ->relationship('cover', 'original_name', fn (Builder $query) => $query->where('mime_type', 'like', 'image/%')->latest('id'))
                        ->searchable()->preload(),
                    Select::make('categories')->relationship('categories', 'name')->multiple()->preload(),
                    Select::make('tags')->relationship('tags', 'name')->multiple()->preload()
                        ->createOptionForm([
                            TextInput::make('name')->required()->maxLength(120),
                            TextInput::make('slug')->required()->regex(SiteUrl::SLUG_PATTERN)->validationMessages(['regex' => SiteUrl::SLUG_HELP])->maxLength(120)->unique('tags', 'slug'),
                        ]),
                ]),
                Section::make('Search engines')->collapsed()->schema([
                    TextInput::make('meta_title')->label('Search title')->maxLength(255),
                    Textarea::make('meta_description')->label('Search description')->rows(3)->maxLength(500),
                ]),
            ])->columnSpan(['lg' => 1]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $tz = config('app.firm_timezone');

        return $table
            ->defaultSort('published_at', 'desc')
            ->columns([
                TextColumn::make('title')->searchable()->sortable()->wrap(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => Post::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'published' => 'success', 'scheduled' => 'info', 'review' => 'warning', default => 'gray',
                    }),
                TextColumn::make('author_name')->label('Author')->placeholder('—'),
                TextColumn::make('published_at')->label('Published')->dateTime('j M Y', $tz)->sortable()->placeholder('—'),
                TextColumn::make('updated_at')->label('Last change')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(Post::STATUSES),
                SelectFilter::make('categories')->relationship('categories', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
                self::deleteAction(DeleteAction::make()),
            ]);
    }

    public static function deleteAction(DeleteAction $action): DeleteAction
    {
        return $action->after(fn (Post $record) => Audit::record('post.deleted', "Deleted post {$record->title}", $record,
            changes: ['before' => $record->only('title', 'slug', 'status')]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPosts::route('/'),
            'create' => CreatePost::route('/create'),
            'edit' => EditPost::route('/{record}/edit'),
        ];
    }
}
