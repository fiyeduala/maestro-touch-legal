<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Page;
use App\Models\PageRevision;
use App\Models\Post;
use App\Models\Tag;
use App\Support\SiteUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/** Public website: CMS pages, blog listing/archives/search and single posts. */
class SiteController extends Controller
{
    public const PER_PAGE = 10; // WordPress default posts_per_page

    public function home(Request $request): View
    {
        if ($request->filled('s')) {
            return $this->search($request);
        }

        return $this->renderPage($this->publishedPage('/'));
    }

    /** "/{slug}/": a CMS page first, then a post (WordPress permalink structure /%postname%/). */
    public function resolve(string $slug): View
    {
        if ($page = Page::with('publishedRevision')->where('path', '/'.$slug.'/')->first()) {
            abort_unless($page->publishedRevision, 404);

            return $this->renderPage($page);
        }

        $post = Post::visible()->where('slug', $slug)->with(['cover', 'categories', 'tags', 'author'])->firstOrFail();

        return $this->renderPost($post);
    }

    public function blog(int $page = 1): View
    {
        return $this->listing(Post::visible(), $page, 'Blog', fn (int $n) => SiteUrl::to($n > 1 ? "/blog/page/$n" : '/blog'), SiteUrl::to('/blog'));
    }

    public function category(string $slug, int $page = 1): View
    {
        $category = Category::where('slug', $slug)->firstOrFail();

        return $this->listing(
            Post::visible()->whereHas('categories', fn ($q) => $q->whereKey($category->id)),
            $page, 'Category: '.$category->name,
            fn (int $n) => SiteUrl::to($n > 1 ? "/category/$slug/page/$n" : "/category/$slug"),
            SiteUrl::to("/category/$slug"),
        );
    }

    public function tag(string $slug, int $page = 1): View
    {
        $tag = Tag::where('slug', $slug)->firstOrFail();

        return $this->listing(
            Post::visible()->whereHas('tags', fn ($q) => $q->whereKey($tag->id)),
            $page, 'Tag: '.$tag->name,
            fn (int $n) => SiteUrl::to($n > 1 ? "/tag/$slug/page/$n" : "/tag/$slug"),
            SiteUrl::to("/tag/$slug"),
        );
    }

    public function search(Request $request): View
    {
        $term = mb_substr(trim((string) $request->query('s')), 0, 100);
        $page = max(1, (int) $request->query('paged', 1));
        $like = '%'.addcslashes($term, "%_\\").'%';

        $query = Post::visible()->where(fn ($q) => $q->where('title', 'like', $like)
            ->orWhere('excerpt', 'like', $like)->orWhere('body', 'like', $like));

        return $this->listing($query, $page, 'Search Results for: '.$term,
            fn (int $n) => url('/').'/?'.http_build_query(array_filter(['s' => $term, 'paged' => $n > 1 ? $n : null])),
            SiteUrl::to('/'), noindex: true);
    }

    /** Draft preview for staff who may edit content. Never cached, never indexed. */
    public function previewPage(Page $page, ?PageRevision $revision = null): Response
    {
        Gate::authorize('manage-content');
        $revision ??= $page->draftRevision ?? $page->publishedRevision;
        abort_unless($revision && $revision->page_id === $page->id, 404);

        return $this->previewResponse($this->renderPage($page, $revision, preview: true));
    }

    public function previewPost(Post $post): Response
    {
        Gate::authorize('manage-content');

        return $this->previewResponse($this->renderPost($post->load(['cover', 'categories', 'tags', 'author']), preview: true));
    }

    /** Unpublished content must never be indexed or kept by a shared cache. */
    private function previewResponse(View $view): Response
    {
        return response($view)
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'private, no-store');
    }

    private function publishedPage(string $path): Page
    {
        $page = Page::with('publishedRevision')->where('path', $path)->firstOrFail();
        abort_unless($page->publishedRevision, 404);

        return $page;
    }

    private function renderPage(Page $page, ?PageRevision $revision = null, bool $preview = false): View
    {
        $revision ??= $page->publishedRevision;
        abort_unless(view()->exists('pages.templates.'.$page->template), 500, 'Unknown page template.');

        return view('pages.show', [
            'page' => $page,
            'revision' => $revision,
            'preview' => $preview,
            'canonical' => SiteUrl::to($page->path),
        ]);
    }

    private function renderPost(Post $post, bool $preview = false): View
    {
        $visible = Post::visible();
        $previous = $post->published_at ? (clone $visible)->where('published_at', '<', $post->published_at)->orderByDesc('published_at')->first(['id', 'slug', 'title']) : null;
        $next = $post->published_at ? (clone $visible)->where('published_at', '>', $post->published_at)->orderBy('published_at')->first(['id', 'slug', 'title']) : null;

        $categoryIds = $post->categories->modelKeys();
        $related = Post::visible()->whereKeyNot($post->id)
            ->when($categoryIds, fn ($q) => $q->whereHas('categories', fn ($c) => $c->whereIn('categories.id', $categoryIds)))
            ->with(['cover', 'categories'])->inRandomOrder()->limit(2)->get();

        return view('blog.show', [
            'post' => $post,
            'previous' => $previous,
            'next' => $next,
            'related' => $related,
            'comments' => $post->comments_visible ? $post->approvedComments()->get() : collect(),
            'preview' => $preview,
            'canonical' => SiteUrl::to('/'.$post->slug),
        ]);
    }

    private function listing(Builder $query, int $page, string $heading, callable $pageUrl, string $canonical, bool $noindex = false): View
    {
        $posts = $query->with(['cover'])->orderByDesc('published_at')->paginate(self::PER_PAGE, ['*'], 'page', $page);
        abort_if($page > 1 && $posts->isEmpty(), 404);

        return view('blog.index', [
            'posts' => $posts,
            'heading' => $heading,
            'pageUrl' => $pageUrl,
            'canonical' => $page > 1 ? $pageUrl($page) : $canonical,
            'noindex' => $noindex,
        ]);
    }
}
