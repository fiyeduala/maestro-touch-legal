<?php

namespace App\Domain\Content;

use App\Domain\Operations\Audit;
use App\Models\Page;
use App\Models\PageRevision;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Page edits never change a published revision in place: editors save a new draft revision,
 * preview it, then publish it. The previously published revision is kept as "superseded" for rollback.
 */
class PageRevisions
{
    private const LEGAL_LINKS = [
        '/terms-and-conditions/' => 'Terms and Conditions',
        '/privacy-policy/' => 'Privacy Policy',
    ];

    private const LEGAL_LINKS_CACHE = 'content.footer_legal_links';

    /** @return array<string, string> path => label, for legal pages that are currently published. */
    public static function publishedLegalLinks(): array
    {
        $published = Cache::rememberForever(self::LEGAL_LINKS_CACHE, fn () => Page::query()
            ->whereIn('path', array_keys(self::LEGAL_LINKS))
            ->whereNotNull('published_revision_id')
            ->pluck('path')->all());

        return array_intersect_key(self::LEGAL_LINKS, array_flip($published));
    }

    /**
     * @param  array{title: string, content: array, meta_title?: ?string, meta_description?: ?string, noindex?: bool}  $data
     */
    public function saveDraft(Page $page, array $data, ?User $author = null, ?string $note = null): PageRevision
    {
        return DB::transaction(function () use ($page, $data, $author, $note) {
            $page = Page::lockForUpdate()->findOrFail($page->id);
            $number = (int) $page->revisions()->max('number') + 1;

            // Only one open draft per page: an older unpublished draft is superseded by the new one.
            PageRevision::where('page_id', $page->id)->where('status', 'draft')->update(['status' => 'superseded']);

            $revision = $page->revisions()->create([
                'number' => $number,
                'title' => $data['title'],
                'meta_title' => $data['meta_title'] ?? null,
                'meta_description' => $data['meta_description'] ?? null,
                'noindex' => (bool) ($data['noindex'] ?? false),
                'content' => $data['content'],
                'status' => 'draft',
                'note' => $note,
                'author_id' => $author?->id,
            ]);
            $page->update(['draft_revision_id' => $revision->id]);

            Audit::record('page.draft_saved', "Saved draft r{$number} of {$page->path}", $page, context: ['revision' => $number], actor: $author);

            return $revision;
        });
    }

    public function publish(PageRevision $revision, ?User $publisher = null): void
    {
        DB::transaction(function () use ($revision, $publisher) {
            $page = Page::lockForUpdate()->findOrFail($revision->page_id);
            $revision = PageRevision::lockForUpdate()->findOrFail($revision->id);

            PageRevision::where('page_id', $page->id)->where('status', 'published')
                ->whereKeyNot($revision->id)->update(['status' => 'superseded']);

            $revision->update([
                'status' => 'published',
                'published_at' => now(),
                'published_by' => $publisher?->id,
            ]);
            $page->update([
                'title' => $revision->title,
                'published_revision_id' => $revision->id,
                'draft_revision_id' => $page->draft_revision_id === $revision->id ? null : $page->draft_revision_id,
            ]);

            DB::afterCommit(fn () => Cache::forget(self::LEGAL_LINKS_CACHE));
            Audit::record('page.published', "Published r{$revision->number} of {$page->path}", $page, context: ['revision' => $revision->number], actor: $publisher);
        });
    }

    /** Rollback = publish a copy of an older revision as a new revision, so history stays linear. */
    public function restore(PageRevision $old, ?User $actor = null): PageRevision
    {
        $copy = $this->saveDraft($old->page, [
            'title' => $old->title,
            'content' => $old->content,
            'meta_title' => $old->meta_title,
            'meta_description' => $old->meta_description,
            'noindex' => $old->noindex,
        ], $actor, "Restored from r{$old->number}");
        $this->publish($copy, $actor);

        return $copy;
    }

    /** Drops the open draft; the published revision (if any) is untouched and the draft stays in history. */
    public function discardDraft(Page $page, ?User $actor = null): void
    {
        DB::transaction(function () use ($page, $actor) {
            $page = Page::lockForUpdate()->findOrFail($page->id);
            $draft = $page->draft_revision_id ? PageRevision::find($page->draft_revision_id) : null;
            if (! $draft) {
                return;
            }
            $draft->update(['status' => 'superseded']);
            $page->update(['draft_revision_id' => null]);
            Audit::record('page.draft_discarded', "Discarded draft r{$draft->number} of {$page->path}", $page, context: ['revision' => $draft->number], actor: $actor);
        });
    }

    public function unpublish(Page $page, ?User $actor = null): void
    {
        abort_if($page->is_system && $page->path === '/', 422, 'The home page cannot be unpublished.');

        DB::transaction(function () use ($page, $actor) {
            PageRevision::where('page_id', $page->id)->where('status', 'published')->update(['status' => 'superseded']);
            $page->update(['published_revision_id' => null]);
            DB::afterCommit(fn () => Cache::forget(self::LEGAL_LINKS_CACHE));
            Audit::record('page.unpublished', "Unpublished {$page->path}", $page, actor: $actor);
        });
    }
}
