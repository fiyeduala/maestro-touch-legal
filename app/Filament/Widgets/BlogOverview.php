<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Comments\CommentResource;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Comment;
use App\Models\PostView;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;

/** Blog analytics on the dashboard (D51), for full administrators and Content Editors. */
class BlogOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 10;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Blog, last 30 days';

    public static function canView(): bool
    {
        return Gate::allows('manage-content');
    }

    protected function getStats(): array
    {
        $since = now(config('app.firm_timezone'))->subDays(29)->toDateString();
        $recent = fn () => PostView::query()->where('viewed_on', '>=', $since);
        $pending = Comment::where('status', 'pending')->count();

        return [
            Stat::make('Visits', $recent()->count())
                ->description('One per visitor, per post, per day')
                ->url(PostResource::getUrl()),
            Stat::make('Reads', $recent()->whereNotNull('read_at')->count())
                ->description('Reached the end of the post'),
            Stat::make('Signed-in clients', $recent()->whereNotNull('user_id')->distinct()->count('user_id'))
                ->description('Named on each post\'s analytics'),
            Stat::make('New comments', Comment::where('posted_at', '>=', now()->subDays(30))->count())
                ->description($pending.' awaiting review')
                ->color($pending ? 'warning' : null)
                ->url(CommentResource::getUrl()),
        ];
    }
}
