<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Posts\PostResource;
use App\Models\Post;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/** The most visited posts over the last 30 days (D51). Opens each post's analytics. */
class TopBlogPosts extends TableWidget
{
    protected static ?int $sort = 11;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Gate::allows('manage-content');
    }

    public function table(Table $table): Table
    {
        $since = now(config('app.firm_timezone'))->subDays(29)->toDateString();
        $recent = fn (Builder $q) => $q->where('viewed_on', '>=', $since);

        return $table
            ->heading('Most visited posts, last 30 days')
            ->query(fn () => Post::query()
                ->whereHas('views', $recent)
                ->withCount([
                    'views as recent_views' => $recent,
                    'views as recent_reads' => fn (Builder $q) => $recent($q)->whereNotNull('read_at'),
                ])
                ->orderByDesc('recent_views'))
            ->defaultPaginationPageOption(5)
            ->paginated([5])
            ->emptyStateHeading('No visits recorded in the last 30 days')
            ->columns([
                TextColumn::make('title')->wrap(),
                TextColumn::make('recent_views')->label('Visits')->numeric(),
                TextColumn::make('recent_reads')->label('Reads')->numeric(),
            ])
            ->recordUrl(fn (Post $record) => PostResource::getUrl('analytics', ['record' => $record]));
    }
}
