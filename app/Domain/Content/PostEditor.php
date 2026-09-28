<?php

namespace App\Domain\Content;

use App\Domain\Operations\Audit;
use App\Models\Post;
use App\Models\PostRevision;
use App\Models\Redirect;
use App\Models\User;
use App\Policies\PostPolicy;
use Illuminate\Validation\ValidationException;
use Mews\Purifier\Facades\Purifier;

/**
 * Rules applied whenever a post is saved from the admin, whoever the editor is:
 * publishing needs the publish ability, the body is sanitised, and a live post that
 * changes address keeps its old URL working through a redirect.
 */
class PostEditor
{
    /** @param  array<string, mixed>  $data */
    public function prepare(array $data, User $actor, ?Post $existing = null): array
    {
        $status = $data['status'] ?? 'draft';
        $wasLive = $existing && in_array($existing->getOriginal('status'), PostPolicy::LIVE_STATUSES, true);
        $becomesLive = in_array($status, PostPolicy::LIVE_STATUSES, true);

        if (($becomesLive || $wasLive) && ! $actor->can('publish', Post::class)) {
            throw ValidationException::withMessages([
                'data.status' => 'Only someone with publishing rights can publish or change a live post.',
            ]);
        }

        $data['body'] = Purifier::clean((string) ($data['body'] ?? ''), 'content');
        $data['content_modified_at'] = now();

        if ($status === 'published' && empty($data['published_at'])) {
            $data['published_at'] = now();
        }
        if ($becomesLive && (! $existing || $existing->getOriginal('status') !== $status)) {
            $data['published_by'] = $actor->id;
        }
        if ($status === 'review' && $existing?->getOriginal('status') !== 'review') {
            $data['submitted_by'] = $actor->id;
        }
        if (! $existing) {
            $data['created_by'] = $actor->id;
            $data['author_id'] = $actor->id;
        }

        return $data;
    }

    /** Called after the post and its relations are saved. */
    public function afterSave(Post $post, User $actor, string $reason, ?string $previousSlug = null, bool $wasLive = false): void
    {
        if ($previousSlug !== null && $previousSlug !== $post->slug && $wasLive) {
            $this->redirectOldSlug($previousSlug, $post, $actor);
        }

        PostRevision::create([
            'post_id' => $post->id,
            'snapshot' => $post->only(['title', 'slug', 'excerpt', 'body', 'status', 'published_at', 'author_name', 'cover_media_id', 'meta_title', 'meta_description']),
            'actor_id' => $actor->id,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    private function redirectOldSlug(string $oldSlug, Post $post, User $actor): void
    {
        $from = Redirect::normalise('/'.$oldSlug.'/');
        $to = '/'.$post->slug.'/';

        // A redirect sitting on the new address would hide the post, so it goes.
        Redirect::where('from_path', Redirect::normalise($to))->delete();
        // Anything that pointed at the old address now points straight at the new one (no chains).
        Redirect::where('to_path', $from)->update(['to_path' => $to]);

        Redirect::updateOrCreate(['from_path' => $from], [
            'to_path' => $to, 'status_code' => 301, 'source' => 'system', 'created_by' => $actor->id,
        ]);

        Audit::record('redirect.created', "Post address changed: {$from} → {$to}", $post,
            changes: ['before' => ['slug' => $oldSlug], 'after' => ['slug' => $post->slug]]);
    }
}
