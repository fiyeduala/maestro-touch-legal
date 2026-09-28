<?php

namespace App\Domain\Content;

use App\Domain\Operations\Audit;
use App\Models\Category;
use App\Models\Comment;
use App\Models\ImportMapping;
use App\Models\ImportRun;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostRevision;
use App\Models\Redirect;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mews\Purifier\Facades\Purifier;
use RuntimeException;
use Throwable;

/**
 * Imports posts, media records, categories, tags and comments from the WordPress REST API format
 * (a captured directory of JSON files, or the live site's public endpoints).
 *
 * - Idempotent: every source object is tracked in import_mappings with a checksum; unchanged objects are skipped.
 * - Never overwrites a post edited locally after its last import; that is reported as an issue instead.
 * - Media files are not downloaded: legacy uploads are served from public/wp-content/uploads (DECISIONS D3), and
 *   a missing local file is reported.
 * - Dry run executes the same code inside a transaction that is rolled back, so its counts are exact.
 * - Source content is data: HTML is purified before storage and nothing in it is executed or followed.
 */
class WordPressImporter
{
    private const SOURCE = 'wordpress-rest';

    private const POST_STATUS = [
        'publish' => 'published',
        'future' => 'scheduled',
        'draft' => 'draft',
        'pending' => 'review',
        'private' => 'private',
    ];

    private const COMMENT_STATUS = [
        'approved' => 'approved',
        'hold' => 'pending',
        'spam' => 'spam',
        'trash' => 'hidden',
    ];

    /** @var array<string, int> */
    private array $stats = [];

    /** @var list<array{level: string, type: string, id: string, message: string}> */
    private array $issues = [];

    private ?ImportRun $run = null;

    /**
     * @param  array{
     *     data: array<string, list<array<string, mixed>>>,
     *     authors?: array<int|string, string>,
     *     html_path?: ?string,
     *     draft_slugs?: list<string>,
     *     dry_run?: bool,
     *     actor?: ?User,
     *     site_hosts?: list<string>,
     * }  $options
     */
    public function import(array $options): ImportRun
    {
        $this->stats = [];
        $this->issues = [];
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $actor = $options['actor'] ?? null;

        $this->run = ImportRun::create([
            'source' => self::SOURCE,
            'dry_run' => $dryRun,
            'status' => 'running',
            'actor_id' => $actor?->id,
            'started_at' => now(),
        ]);

        try {
            DB::beginTransaction();
            $this->process($options);

            if ($dryRun) {
                DB::rollBack();
            } else {
                Audit::record('content.wordpress_imported', 'Imported WordPress content', $this->run,
                    context: ['stats' => $this->stats, 'issues' => count($this->issues)], actor: $actor);
                DB::commit();
            }
            $status = 'completed';
        } catch (Throwable $e) {
            DB::rollBack();
            $this->issue('error', 'run', '-', $e->getMessage());
            $status = 'failed';
        }

        $this->run->update([
            'status' => $status,
            'stats' => $this->stats,
            'issues' => $this->issues,
            'finished_at' => now(),
            'report_path' => $this->writeReport($status, $dryRun),
        ]);

        if (isset($e)) {
            throw $e;
        }

        return $this->run;
    }

    /** Reads {posts,media,categories,tags,comments,users}.json from a captured REST directory. */
    public static function readDirectory(string $dir): array
    {
        if (! is_dir($dir)) {
            throw new RuntimeException("Source directory not found: {$dir}");
        }

        $data = [];
        foreach (['categories', 'tags', 'media', 'posts', 'comments', 'users'] as $type) {
            $file = rtrim($dir, '/\\').DIRECTORY_SEPARATOR."{$type}.json";
            $data[$type] = is_file($file) ? json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : [];
        }

        return $data;
    }

    /** Reads the same collections from a live site's public REST API (read-only GET requests). */
    public static function readLive(string $baseUrl): array
    {
        $base = rtrim($baseUrl, '/').'/wp-json/wp/v2/';
        $data = [];

        foreach (['categories', 'tags', 'media', 'posts', 'comments', 'users'] as $type) {
            $data[$type] = [];
            $page = 1;
            do {
                $response = Http::timeout(30)->retry(2, 1000, throw: false)->acceptJson()
                    ->get($base.$type, ['per_page' => 100, 'page' => $page]);
                if (! $response->successful()) {
                    // The users endpoint is often disabled; bylines then fall back to other sources.
                    if ($type === 'users') {
                        break;
                    }
                    throw new RuntimeException("GET {$base}{$type} page {$page} failed with HTTP {$response->status()}");
                }
                $data[$type] = array_merge($data[$type], $response->json() ?? []);
                $pages = (int) $response->header('X-WP-TotalPages') ?: 1;
            } while (++$page <= $pages);
        }

        return $data;
    }

    private function process(array $options): void
    {
        $data = $options['data'];
        $hosts = $options['site_hosts'] ?? ['mtouchlegal.com', 'www.mtouchlegal.com'];
        $draftSlugs = $options['draft_slugs'] ?? [];
        $authors = $this->resolveAuthors($data, $options['authors'] ?? []);

        $categoryIds = $this->importTerms($data['categories'] ?? [], 'category', Category::class, true);
        $tagIds = $this->importTerms($data['tags'] ?? [], 'tag', Tag::class, false);
        $mediaIds = $this->importMedia($data['media'] ?? []);

        foreach ($data['posts'] ?? [] as $source) {
            $this->importPost($source, $hosts, $draftSlugs, $authors, $options['html_path'] ?? null,
                $categoryIds, $tagIds, $mediaIds, $options['actor'] ?? null);
        }

        foreach ($data['comments'] ?? [] as $source) {
            $this->importComment($source);
        }
    }

    /**
     * @param  class-string<Category|Tag>  $model
     * @return array<string, int> source id => local id
     */
    private function importTerms(array $items, string $type, string $model, bool $withDescription): array
    {
        $ids = [];
        foreach ($items as $source) {
            $attributes = ['name' => $this->text($source['name'] ?? ''), 'slug' => (string) $source['slug']];
            if ($withDescription) {
                $attributes['description'] = $this->text($source['description'] ?? '') ?: null;
            }

            $ids[(string) $source['id']] = $this->upsert($type, $source, $model, $attributes,
                fn () => $model::where('slug', $attributes['slug'])->first());
        }

        return $ids;
    }

    /** @return array<string, int> */
    private function importMedia(array $items): array
    {
        $ids = [];
        foreach ($items as $source) {
            $path = ltrim((string) parse_url((string) ($source['source_url'] ?? ''), PHP_URL_PATH), '/');
            if (! str_starts_with($path, 'wp-content/uploads/')) {
                $this->issue('warning', 'media', (string) $source['id'], "Unexpected media URL, skipped: {$source['source_url']}");

                continue;
            }

            $file = public_path($path);
            $exists = is_file($file);
            if (! $exists) {
                $this->issue('warning', 'media', (string) $source['id'], "File missing locally: /{$path}. Copy it into public/{$path}.");
            }
            $size = $exists ? @getimagesize($file) : false;

            $attributes = [
                'disk' => 'legacy',
                'path' => $path,
                'original_name' => basename($path),
                'mime_type' => (string) ($source['mime_type'] ?? 'application/octet-stream'),
                'size' => $exists ? filesize($file) : (int) ($source['media_details']['filesize'] ?? 0),
                'width' => $size ? $size[0] : ($source['media_details']['width'] ?? null),
                'height' => $size ? $size[1] : ($source['media_details']['height'] ?? null),
                'alt_text' => $this->text($source['alt_text'] ?? '') ?: null,
                'caption' => $this->text($source['caption']['rendered'] ?? '') ?: null,
                'sha256' => $exists ? hash_file('sha256', $file) : str_repeat('0', 64),
            ];

            $ids[(string) $source['id']] = $this->upsert('media', $source, Media::class, $attributes,
                fn () => Media::where('path', $path)->first());
        }

        return $ids;
    }

    private function importPost(array $source, array $hosts, array $draftSlugs, array $authors, ?string $htmlPath,
        array $categoryIds, array $tagIds, array $mediaIds, ?User $actor): void
    {
        $id = (string) $source['id'];
        $slug = (string) $source['slug'];
        $status = self::POST_STATUS[$source['status'] ?? ''] ?? null;
        if ($status === null) {
            $this->issue('warning', 'post', $id, "Unsupported status '{$source['status']}', skipped.");

            return;
        }
        if (in_array($slug, $draftSlugs, true)) {
            $status = 'draft';
            $this->issue('info', 'post', $id, "'{$slug}' imported as a draft (not public) pending owner decision.");
        }

        $body = $this->rewriteUrls((string) ($source['content']['rendered'] ?? ''), $hosts);
        $this->checkLocalUploads($body, $id);

        $authorName = $authors[(string) ($source['author'] ?? '')]
            ?? $this->bylineFromHtml($htmlPath, $slug);
        if ($authorName === null) {
            $this->issue('warning', 'post', $id, "No author name found for WordPress user {$source['author']}; byline falls back to the firm name. Use --author={$source['author']}=\"Name\".");
        }

        $attributes = [
            'title' => $this->text($source['title']['rendered'] ?? ''),
            'slug' => $slug,
            'excerpt' => $this->excerpt($source['excerpt']['rendered'] ?? ''),
            'body' => Purifier::clean($body, 'content'),
            'cover_media_id' => $mediaIds[(string) ($source['featured_media'] ?? '')] ?? null,
            'author_name' => $authorName,
            'status' => $status,
            'published_at' => $this->gmt($source['date_gmt'] ?? null),
            'content_modified_at' => $this->gmt($source['modified_gmt'] ?? null),
            'comments_visible' => true,
        ];

        $postId = $this->upsert('post', $source, Post::class, $attributes,
            fn () => Post::where('slug', $slug)->first(),
            afterWrite: function (Post $post, string $action) use ($source, $categoryIds, $tagIds, $actor) {
                $post->categories()->sync(array_values(array_filter(array_map(fn ($c) => $categoryIds[(string) $c] ?? null, $source['categories'] ?? []))));
                $post->tags()->sync(array_values(array_filter(array_map(fn ($t) => $tagIds[(string) $t] ?? null, $source['tags'] ?? []))));
                PostRevision::create([
                    'post_id' => $post->id,
                    'snapshot' => $post->only(['title', 'slug', 'excerpt', 'body', 'status', 'published_at', 'author_name', 'cover_media_id']),
                    'actor_id' => $actor?->id,
                    'reason' => "wordpress_import_{$action}",
                    'created_at' => now(),
                ]);
            });

        // The WordPress permalink is /{slug}/ (same as here); anything else gets a redirect to keep old links alive.
        $oldPath = (string) parse_url((string) ($source['link'] ?? ''), PHP_URL_PATH);
        $newPath = '/'.$slug.'/';
        if ($postId && $oldPath !== '' && $oldPath !== $newPath && ! Redirect::where('from_path', $oldPath)->exists()) {
            Redirect::create(['from_path' => $oldPath, 'to_path' => $newPath, 'status_code' => 301, 'source' => 'wordpress']);
            $this->count('redirect.created');
        }
    }

    private function importComment(array $source): void
    {
        $postMapping = $this->mapping('post', (string) ($source['post'] ?? ''));
        if (! $postMapping?->target_id) {
            $this->issue('warning', 'comment', (string) $source['id'], "Parent post {$source['post']} was not imported; comment skipped.");

            return;
        }

        $attributes = [
            'post_id' => $postMapping->target_id,
            'author_name' => $this->text($source['author_name'] ?? '') ?: 'Anonymous',
            'author_email' => null, // not exposed by the public API
            'body' => Purifier::clean((string) ($source['content']['rendered'] ?? ''), 'comment'),
            'status' => self::COMMENT_STATUS[$source['status'] ?? ''] ?? 'pending',
            'posted_at' => $this->gmt($source['date_gmt'] ?? null) ?? now(),
        ];

        $this->upsert('comment', $source, Comment::class, $attributes, fn () => null);
    }

    /**
     * Creates or updates the local record for one source object, tracked by import_mappings.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     */
    private function upsert(string $type, array $source, string $model, array $attributes, callable $findExisting, ?callable $afterWrite = null): ?int
    {
        $sourceId = (string) $source['id'];
        $checksum = hash('sha256', json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $mapping = $this->mapping($type, $sourceId);
        $record = $mapping?->target_id ? $model::find($mapping->target_id) : null;

        if ($record && $mapping->checksum === $checksum) {
            $this->count("{$type}.unchanged");

            return $record->getKey();
        }

        if ($record && $record->updated_at && $record->updated_at->gt($mapping->updated_at->copy()->addSecond())) {
            $this->issue('warning', $type, $sourceId, 'Changed in WordPress but also edited here since the last import; local version kept.');
            $this->count("{$type}.conflict");

            return $record->getKey();
        }

        if (! $record && ($existing = $findExisting())) {
            // A record with the same natural key already exists (created by hand): link it but never overwrite it.
            $this->issue('info', $type, $sourceId, 'Matched an existing local record; linked without overwriting.');
            $this->saveMapping($type, $source, $existing, $checksum);
            $this->count("{$type}.linked");

            return $existing->getKey();
        }

        $action = $record ? 'updated' : 'created';
        $record ??= new $model;
        $record->fill($attributes)->save();
        if ($afterWrite) {
            $afterWrite($record, $action);
        }
        $this->saveMapping($type, $source, $record, $checksum);
        $this->count("{$type}.{$action}");

        return $record->getKey();
    }

    private function mapping(string $type, string $sourceId): ?ImportMapping
    {
        return ImportMapping::where(['source' => self::SOURCE, 'source_type' => $type, 'source_id' => $sourceId])->first();
    }

    private function saveMapping(string $type, array $source, $record, string $checksum): void
    {
        $mapping = ImportMapping::firstOrNew(['source' => self::SOURCE, 'source_type' => $type, 'source_id' => (string) $source['id']]);
        $mapping->fill([
            'source_url' => $source['link'] ?? $source['source_url'] ?? null,
            'target_type' => $record->getMorphClass(),
            'target_id' => $record->getKey(),
            'checksum' => $checksum,
            'import_run_id' => $this->run?->id,
        ]);
        // Always bump updated_at so local-edit detection compares against this import.
        $mapping->updated_at = now()->addSecond();
        $mapping->save();
    }

    /** @return array<string, string> WordPress user id => display name */
    private function resolveAuthors(array $data, array $overrides): array
    {
        $authors = [];
        foreach ($data['users'] ?? [] as $user) {
            if (! empty($user['name'])) {
                $authors[(string) $user['id']] = $this->text($user['name']);
            }
        }

        return array_map('strval', $overrides) + $authors;
    }

    /** Reads the author's name from the schema.org Person block of a captured post page. */
    private function bylineFromHtml(?string $htmlPath, string $slug): ?string
    {
        $file = $htmlPath ? rtrim($htmlPath, '/\\').DIRECTORY_SEPARATOR.$slug.'.html' : null;
        if (! $file || ! is_file($file)) {
            return null;
        }

        return preg_match('/"@type":"Person","@id":"[^"]*#person","name":"([^"]{1,120})"/', (string) file_get_contents($file), $m)
            ? $this->text(json_decode('"'.$m[1].'"') ?? $m[1])
            : null;
    }

    /** Makes links to the old site relative, so content works on any host (local, staging, production). */
    private function rewriteUrls(string $html, array $hosts): string
    {
        $hostPattern = implode('|', array_map(fn ($h) => preg_quote($h, '#'), $hosts));

        return (string) preg_replace('#https?://(?:'.$hostPattern.')(?=/)#i', '', $html);
    }

    private function checkLocalUploads(string $html, string $postId): void
    {
        preg_match_all('#/wp-content/uploads/[^"\'\s,)<>?]+#', $html, $m);
        foreach (array_unique($m[0]) as $path) {
            if (! is_file(public_path(ltrim(rawurldecode($path), '/')))) {
                $this->issue('warning', 'post', $postId, "Body references a file missing locally: {$path}");
            }
        }
    }

    private function excerpt(string $html): ?string
    {
        // WordPress auto-excerpts end with " [&hellip;]"; the listing adds its own truncation.
        $text = preg_replace('/\s*\[(?:&hellip;|…|\.\.\.)\]\s*$/u', '', $this->text($html));

        return $text !== '' ? $text : null;
    }

    private function text(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function gmt(?string $value): ?Carbon
    {
        return $value && ! str_starts_with($value, '0000') ? Carbon::parse($value, 'UTC') : null;
    }

    private function count(string $key): void
    {
        $this->stats[$key] = ($this->stats[$key] ?? 0) + 1;
    }

    private function issue(string $level, string $type, string $id, string $message): void
    {
        $this->issues[] = compact('level', 'type', 'id', 'message');
    }

    private function writeReport(string $status, bool $dryRun): ?string
    {
        $path = 'import-reports/wordpress-'.$this->run->id.'-'.now()->format('Ymd-His').($dryRun ? '-dry-run' : '').'.json';

        try {
            Storage::disk('local')->put($path, json_encode([
                'run' => $this->run->id,
                'status' => $status,
                'dry_run' => $dryRun,
                'stats' => $this->stats,
                'issues' => $this->issues,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (Throwable) {
            return null;
        }

        return $path;
    }

    public function stats(): array
    {
        return $this->stats;
    }

    public function issues(): array
    {
        return $this->issues;
    }
}
