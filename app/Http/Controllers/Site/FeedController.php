<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\Tag;
use App\Support\SiteUrl;
use Illuminate\Http\Response;

/** RSS feed, XML sitemap and robots.txt. */
class FeedController extends Controller
{
    public function feed(): Response
    {
        $posts = Post::visible()->with(['cover', 'categories'])->orderByDesc('published_at')->limit(10)->get();

        return response()->view('feeds.rss', ['posts' => $posts])
            ->header('Content-Type', 'application/rss+xml; charset=UTF-8');
    }

    public function sitemap(): Response
    {
        $urls = collect();

        Page::with('publishedRevision')->whereNotNull('published_revision_id')->get()
            ->reject(fn (Page $p) => $p->publishedRevision?->noindex)
            ->each(fn (Page $p) => $urls->push(['loc' => SiteUrl::to($p->path), 'lastmod' => $p->publishedRevision->published_at ?? $p->updated_at]));

        $urls->push(['loc' => SiteUrl::to('/blog'), 'lastmod' => Post::visible()->max('published_at')]);

        Post::visible()->orderByDesc('published_at')->get(['slug', 'published_at', 'content_modified_at', 'updated_at'])
            ->each(fn (Post $p) => $urls->push(['loc' => SiteUrl::to('/'.$p->slug), 'lastmod' => $p->content_modified_at ?? $p->published_at]));

        Category::whereHas('posts', fn ($q) => $q->visible())->get(['slug'])
            ->each(fn ($c) => $urls->push(['loc' => SiteUrl::to('/category/'.$c->slug), 'lastmod' => null]));
        Tag::whereHas('posts', fn ($q) => $q->visible())->get(['slug'])
            ->each(fn ($t) => $urls->push(['loc' => SiteUrl::to('/tag/'.$t->slug), 'lastmod' => null]));

        return response()->view('feeds.sitemap', ['urls' => $urls])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function robots(): Response
    {
        // Anything other than production (staging, local) is never indexed.
        $body = app()->isProduction()
            ? "User-agent: *\nDisallow: /admin\nDisallow: /portal\nDisallow: /invitation/\nDisallow: /careers/application/\nDisallow: /*?s=\n\nSitemap: ".url('/sitemap.xml')."\n"
            : "User-agent: *\nDisallow: /\n";

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
