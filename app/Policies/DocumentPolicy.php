<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** Every document ability delegates to the matter (or enquiry) it belongs to. */
class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        if ($user->isFullAdministrator()) {
            return true;
        }
        if ($document->matter) {
            return Gate::forUser($user)->allows('view', $document->matter);
        }
        if ($document->enquiry) {
            return Gate::forUser($user)->allows('view', $document->enquiry);
        }

        return false;
    }

    public function update(User $user, Document $document): bool
    {
        if ($document->matter) {
            return Gate::forUser($user)->allows('manageDocuments', $document->matter);
        }

        return $user->isFullAdministrator()
            || ($document->enquiry && Gate::forUser($user)->allows('update', $document->enquiry));
    }

    public function approve(User $user, Document $document): bool
    {
        return $document->is_deliverable && $document->matter
            && Gate::forUser($user)->allows('approveDeliverables', $document->matter);
    }

    public function viewAsClient(User $user, Document $document): bool
    {
        return $document->isClientVisible() && $document->matter
            && Gate::forUser($user)->allows('viewAsClient', $document->matter);
    }

    public function decideAsClient(User $user, Document $document): bool
    {
        return $document->is_deliverable && $document->released_version_id !== null && $document->matter
            && Gate::forUser($user)->allows('actAsClient', $document->matter);
    }

    public function delete(User $user, Document $document): bool
    {
        return false;
    }
}
