<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Filament\Resources\Posts\PostResource;
use App\Models\Post;
use App\Models\PostView;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Who has seen a blog post (D51): visit and read counts, where visitors came from, the signed-in
 * clients who opened it, and everyone who commented. Anonymous visitors are counted, never named.
 *
 * @property-read Post $record
 */
class PostAnalytics extends Page
{
    use InteractsWithRecord;

    protected static string $resource = PostResource::class;

    protected string $view = 'filament.post-analytics';

    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;

        return $record instanceof Post && (bool) auth()->user()?->can('view', $record);
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(static::canAccess(['record' => $this->record]), 403);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Analytics: '.$this->record->title;
    }

    public function getBreadcrumb(): string
    {
        return 'Analytics';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('view')
                ->label('View on site')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->visible(fn () => $this->record->isVisible())
                ->url(fn () => $this->record->url(), shouldOpenInNewTab: true),
        ];
    }

    protected function getViewData(): array
    {
        $tz = config('app.firm_timezone');
        $views = fn () => PostView::query()->where('post_id', $this->record->id);
        $since = now($tz)->subDays(29)->toDateString();

        $daily = $views()->where('viewed_on', '>=', $since)
            ->selectRaw('viewed_on, count(*) as total, sum(case when read_at is null then 0 else 1 end) as read_total')
            ->groupBy('viewed_on')->get()
            ->keyBy(fn (PostView $row) => $row->viewed_on->toDateString());

        $days = collect(range(29, 0))->map(function (int $ago) use ($tz, $daily) {
            $day = now($tz)->subDays($ago)->toDateString();

            return ['day' => $day, 'views' => (int) ($daily[$day]->total ?? 0), 'reads' => (int) ($daily[$day]->read_total ?? 0)];
        });

        $readers = $views()->whereNotNull('user_id')->with('user')->get()
            ->groupBy('user_id')
            ->map(fn ($visits) => [
                'user' => $visits->first()->user,
                'last' => $visits->max('created_at'),
                'visits' => $visits->count(),
                'read' => $visits->whereNotNull('read_at')->isNotEmpty(),
            ])
            ->filter(fn (array $r) => $r['user'] !== null)
            ->sortByDesc('last')
            ->values();

        return [
            'tz' => $tz,
            'totals' => [
                'views' => $views()->count(),
                'reads' => $views()->whereNotNull('read_at')->count(),
                'last30' => $days->sum('views'),
                'clients' => $readers->count(),
                'imported' => $views()->where('source', 'access_log')->count(),
                'first' => $views()->min('viewed_on'),
            ],
            'days' => $days,
            'peak' => max(1, $days->max('views')),
            'referrers' => $views()->whereNotNull('referrer_host')
                ->selectRaw('referrer_host, count(*) as total')->groupBy('referrer_host')
                ->orderByDesc('total')->limit(8)->pluck('total', 'referrer_host'),
            'readers' => $readers,
            'comments' => $this->record->comments()->reorder()->latest('posted_at')->get(),
        ];
    }
}
