<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

/**
 * Drafts and posts in review are open to every content manager. Once a post is live (published,
 * scheduled or private), changing or deleting it needs the publish ability, because post edits
 * take effect immediately.
 */
class PostPolicy extends ContentPolicy
{
    public const LIVE_STATUSES = ['published', 'scheduled', 'private'];

    public function update(User $user, mixed $record): bool
    {
        return $this->manages($user) && ($this->isDraft($record) || $this->publish($user, $record));
    }

    public function delete(User $user, mixed $record): bool
    {
        return $this->update($user, $record);
    }

    private function isDraft(Post $post): bool
    {
        return ! in_array($post->getOriginal('status') ?? $post->status, self::LIVE_STATUSES, true);
    }
}
