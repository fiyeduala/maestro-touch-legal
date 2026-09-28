<?php

namespace App\Policies;

use App\Models\User;

class MediaPolicy extends ContentPolicy
{
    /** Imported WordPress files stay: old posts, search results and shares still link to them (DECISIONS D3). */
    public function delete(User $user, mixed $record): bool
    {
        return $this->manages($user) && $record->disk !== 'legacy';
    }
}
