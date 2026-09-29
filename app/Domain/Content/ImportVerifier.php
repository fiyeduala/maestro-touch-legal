<?php

namespace App\Domain\Content;

use App\Models\Category;
use App\Models\Comment;
use App\Models\ImportMapping;
use App\Models\Media;
use App\Models\Page;
use App\Models\Post;
use App\Models\Redirect;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Checks an import against its source, item by item: counts, titles, body text, statuses, dates, taxonomies,
 * cover images, comments, media files, internal links and redirects. Read-only; writes a Markdown report.
 * Differences that were chosen on purpose (local edits kept, posts forced to draft) are listed as notes, not failures.
 */
class ImportVerifier
{
    /** @var list<array{level: string, item: string, message: string}> */
    private array $findings = [];

    private array $counts = [];

    /**
     * @param  array  $data  source data from WordPressImporter::readDirectory/readLive or WxrReader::read
     * @param  list<string>  $draftSlugs  posts deliberately imported as drafts
     * @return array{ok: bool, counts: array, findings: list<array>, report_path: ?string}
     */
    public function verify(array $data, array $draftSlugs = [], bool $write = true): array
    {
        $this->findings = [];
        $this->counts = [];

        $this->checkTerms($data['categories'] ?? [], 'category', Category::class);
        $this->checkTerms($data['tags'] ?? [], 'tag', Tag::class);
        $this->checkMedia($data['media'] ?? []);
        foreach ($data['posts'] ?? [] as $source) {
            $this->checkPost($source, $draftSlugs);
        }
        $this->checkComments($data['comments'] ?? []);
        $this->checkLinks();

        $ok = ! collect($this->findings)->contains('level', 'fail');
        $path = $write ? $this->writeReport($ok, $data) : null;

        return ['ok' => $ok, 'counts' => $this->counts, 'findings' => $this->findings, 'report_path' => $path];
    }

    private function checkTerms(array $items, string $type, string $model): void
    {
        $this->counts[$type] = ['source' => count($items), 'imported' => 0];
        foreach ($items as $source) {
            $local = $this->local($type, $source['id'], $model);
            if (! $local) {
                $this->fail("{$type} {$source['slug']}", 'missing locally');

                continue;
            }
            $this->counts[$type]['imported']++;
            if ($local->slug !== (string) $source['slug']) {
                $this->note("{$type} {$source['slug']}", "slug is now '{$local->slug}'");
            }
        }
    }

    private function checkMedia(array $items): void
    {
        $this->counts['media'] = ['source' => count($items), 'imported' => 0, 'file_present' => 0];
        foreach ($items as $source) {
            $local = $this->local('media', $source['id'], Media::class);
            $label = 'media '.basename((string) parse_url((string) ($source['source_url'] ?? ''), PHP_URL_PATH));
            if (! $local) {
                $this->fail($label, 'missing locally (see the import report for the reason)');

                continue;
            }
            $this->counts['media']['imported']++;
            $present = $local->disk === 'legacy' ? is_file(public_path($local->path)) : Storage::disk($local->disk)->exists($local->path);
            if ($present) {
                $this->counts['media']['file_present']++;
            } else {
                $this->fail($label, "record imported but the file is not on disk: {$local->path}");
            }
        }
    }

    private function checkPost(array $source, array $draftSlugs): void
    {
        $slug = (string) $source['slug'];
        $label = "post {$slug}";
        $this->counts['post'] ??= ['source' => 0, 'imported' => 0, 'matching' => 0];
        $this->counts['post']['source']++;

        /** @var Post|null $post */
        $post = $this->local('post', $source['id'], Post::class);
        if (! $post) {
            $this->fail($label, 'missing locally');

            return;
        }
        $this->counts['post']['imported']++;
        $mapping = ImportMapping::where(['source' => WordPressImporter::SOURCE, 'source_type' => 'post', 'source_id' => (string) $source['id']])->first();
        $editedHere = $post->updated_at && $mapping && $post->updated_at->gt($mapping->updated_at->copy()->addSecond());
        $problems = 0;
        $differs = function (string $what, string $detail) use ($label, $editedHere, &$problems) {
            $problems++;
            $editedHere ? $this->note($label, "{$what} differs ({$detail}); edited here after import, so expected") : $this->fail($label, "{$what} differs: {$detail}");
        };

        $title = self::text($source['title']['rendered'] ?? '');
        if ($post->title !== $title) {
            $differs('title', "'{$post->title}' vs '{$title}'");
        }
        if ($post->slug !== $slug) {
            $differs('slug', $post->slug);
        }

        $expected = in_array($slug, $draftSlugs, true) ? 'draft' : (WordPressImporter::POST_STATUS[$source['status'] ?? ''] ?? '?');
        if ($post->status !== $expected) {
            $differs('status', "{$post->status}, expected {$expected}");
        } elseif (in_array($slug, $draftSlugs, true)) {
            $this->note($label, 'kept as a draft on purpose (owner to decide)');
        }

        $date = ($source['date_gmt'] ?? null) ? Carbon::parse($source['date_gmt'], 'UTC') : null;
        if ($date && (! $post->published_at || abs($post->published_at->diffInSeconds($date)) > 1)) {
            $differs('publish date', ($post->published_at?->toIso8601String() ?? 'none').' vs '.$date->toIso8601String());
        }

        // Body: compare visible text. Sanitising may change markup, but the words must survive.
        $sourceText = self::words($source['content']['rendered'] ?? '');
        $localText = self::words((string) $post->body);
        if ($sourceText !== $localText) {
            similar_text($sourceText, $localText, $percent);
            $percent = round($percent, 1);
            $percent >= 99.5
                ? $this->note($label, "body text {$percent}% identical (markup/whitespace only)")
                : $differs('body text', "{$percent}% similar");
        }

        $wantCategories = $this->localIds('category', $source['categories'] ?? []);
        $wantTags = $this->localIds('tag', $source['tags'] ?? []);
        if ($wantCategories !== $post->categories()->pluck('categories.id')->sort()->values()->all()) {
            $differs('categories', 'set does not match');
        }
        if ($wantTags !== $post->tags()->pluck('tags.id')->sort()->values()->all()) {
            $differs('tags', 'set does not match');
        }

        if (! empty($source['featured_media'])) {
            $cover = $this->local('media', $source['featured_media'], Media::class);
            if ($post->cover_media_id !== $cover?->id) {
                $differs('cover image', $cover ? 'points elsewhere' : 'source image was not imported');
            }
        }

        // Old permalink must still work: same path, or a redirect to the new one.
        $oldPath = (string) parse_url((string) ($source['link'] ?? ''), PHP_URL_PATH);
        if ($oldPath !== '' && $oldPath !== '/' && ! str_contains((string) ($source['link'] ?? ''), '?p=')) {
            $newPath = '/'.$post->slug.'/';
            if (Redirect::normalise($oldPath) !== Redirect::normalise($newPath)) {
                $redirect = Redirect::where('from_path', Redirect::normalise($oldPath))->first();
                if (! $redirect || Redirect::normalise($redirect->to_path) !== Redirect::normalise($newPath)) {
                    $this->fail($label, "old link {$oldPath} has no redirect to {$newPath}");
                } else {
                    $this->counts['redirect'] = ($this->counts['redirect'] ?? 0) + 1;
                }
            }
        }

        if ($problems === 0) {
            $this->counts['post']['matching']++;
        }
    }

    private function checkComments(array $items): void
    {
        $this->counts['comment'] = ['source' => count($items), 'imported' => 0];
        foreach ($items as $source) {
            $local = $this->local('comment', $source['id'], Comment::class);
            if ($local) {
                $this->counts['comment']['imported']++;
            } elseif ($this->local('post', $source['post'] ?? '', Post::class)) {
                $this->fail("comment {$source['id']}", 'missing locally');
            } else {
                $this->note("comment {$source['id']}", 'skipped with its post');
            }
        }
    }

    /** Every link inside imported post bodies that points at this site must lead somewhere. */
    private function checkLinks(): void
    {
        $postIds = ImportMapping::where(['source' => WordPressImporter::SOURCE, 'source_type' => 'post'])->pluck('target_id');
        $checked = 0;
        foreach (Post::whereIn('id', $postIds)->get(['id', 'slug', 'body']) as $post) {
            preg_match_all('#(?:href|src)="(/[^"\#?]*)#i', (string) $post->body, $m);
            foreach (array_unique($m[1]) as $path) {
                $checked++;
                if (($problem = $this->linkProblem(html_entity_decode($path))) !== null) {
                    $this->fail("post {$post->slug}", "link {$path}: {$problem}");
                }
            }
            if (preg_match('#https?://(?:www\.)?mtouchlegal\.com/#i', (string) $post->body)) {
                $this->note("post {$post->slug}", 'still has absolute links to mtouchlegal.com');
            }
        }
        $this->counts['internal_links_checked'] = $checked;
    }

    private function linkProblem(string $path): ?string
    {
        if (str_starts_with($path, '/'.WordPressImporter::IMAGES_DIR.'/')) {
            return is_file(public_path(ltrim(rawurldecode($path), '/'))) ? null : 'file missing';
        }
        if (Redirect::where('from_path', Redirect::normalise($path))->exists()) {
            return null;
        }
        try {
            $route = Route::getRoutes()->match(Request::create($path));
        } catch (HttpException) {
            return 'no page at this address';
        }
        if ($route->getName() !== 'content.show') {
            return null;
        }
        $slug = (string) $route->parameter('slug');
        if (Page::where('path', '/'.$slug.'/')->exists()) {
            return null;
        }
        $post = Post::where('slug', $slug)->first();

        return match (true) {
            $post === null => 'no page or post with this address',
            ! $post->isVisible() => "links to a post that is not public ({$post->status})",
            default => null,
        };
    }

    private function local(string $type, $sourceId, string $model)
    {
        $id = ImportMapping::where(['source' => WordPressImporter::SOURCE, 'source_type' => $type, 'source_id' => (string) $sourceId])->value('target_id');

        return $id ? $model::find($id) : null;
    }

    private function localIds(string $type, array $sourceIds): array
    {
        return ImportMapping::where(['source' => WordPressImporter::SOURCE, 'source_type' => $type])
            ->whereIn('source_id', array_map('strval', $sourceIds))->pluck('target_id')->sort()->values()->all();
    }

    private static function text(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function words(string $html): string
    {
        $html = preg_replace('#<(script|style)\b.*?</\1>#is', '', $html);

        return trim(preg_replace('/\s+/u', ' ', self::text((string) preg_replace('/<[^>]+>/', ' ', (string) $html))));
    }

    private function fail(string $item, string $message): void
    {
        $this->findings[] = ['level' => 'fail', 'item' => $item, 'message' => $message];
    }

    private function note(string $item, string $message): void
    {
        $this->findings[] = ['level' => 'note', 'item' => $item, 'message' => $message];
    }

    private function writeReport(bool $ok, array $data): string
    {
        $lines = ['# WordPress import verification', '',
            'Checked: '.now()->timezone(config('app.firm_timezone'))->format('j M Y H:i T'),
            'Result: **'.($ok ? 'PASSED' : 'DIFFERENCES FOUND').'**', '',
            '| Item | Source | Imported | Notes |', '|---|---|---|---|'];
        foreach ($this->counts as $type => $c) {
            if (is_array($c)) {
                $extra = collect($c)->except(['source', 'imported'])->map(fn ($n, $k) => str_replace('_', ' ', $k).": {$n}")->implode(', ');
                $lines[] = "| {$type} | {$c['source']} | {$c['imported']} | {$extra} |";
            } else {
                $lines[] = '| '.str_replace('_', ' ', $type)." | | {$c} | |";
            }
        }
        foreach (['fail' => 'Differences', 'note' => 'Notes'] as $level => $heading) {
            $rows = array_filter($this->findings, fn ($f) => $f['level'] === $level);
            $lines[] = '';
            $lines[] = "## {$heading} (".count($rows).')';
            $lines[] = '';
            foreach ($rows as $f) {
                $lines[] = "- {$f['item']}: ".str_replace(["\n", '|'], [' ', '/'], $f['message']);
            }
            if ($rows === []) {
                $lines[] = 'None.';
            }
        }
        foreach ($data['notes'] ?? [] as $note) {
            $lines[] = "- source: {$note}";
        }

        $path = 'import-reports/verify-'.now()->format('Ymd-His').'.md';
        Storage::disk('local')->put($path, implode("\n", $lines)."\n");

        return $path;
    }
}
