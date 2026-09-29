<?php

namespace App\Domain\Documents;

use App\Domain\Clients\ClientContacts;
use App\Domain\Matters\Matters;
use App\Domain\Operations\Audit;
use App\Domain\RuleViolation;
use App\Models\Acceptance;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\DocumentRequest;
use App\Models\DocumentVersion;
use App\Models\Enquiry;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Confidential documents (spec §9). Files live on the private "confidential" disk under random names.
 * Staff drafts stay internal until a lawyer (or full administrator) approves and someone releases them;
 * the client then sees exactly the released version, even while a newer internal draft is prepared.
 */
class Documents
{
    public const DISK = 'confidential';

    public const CLIENT_APPROVE_STATEMENT = 'I approve version :version of ":title" as released to me on :date.';

    public const CLIENT_CHANGES_STATEMENT = 'I request changes to version :version of ":title".';

    public function __construct(private Matters $matters, private ClientContacts $contacts) {}

    /**
     * Staff upload to a matter or enquiry.
     *
     * @param  array{title: string, category: string, is_deliverable?: bool, note?: ?string}  $meta
     */
    public function upload(Matter|Enquiry $owner, UploadedFile $file, array $meta, User $actor): Document
    {
        $owner instanceof Matter
            ? Gate::forUser($actor)->authorize('manageDocuments', $owner)
            : Gate::forUser($actor)->authorize('update', $owner);
        $this->assertMeta($meta);
        $deliverable = $owner instanceof Matter && (bool) ($meta['is_deliverable'] ?? false);

        return $this->create([
            'matter_id' => $owner instanceof Matter ? $owner->id : null,
            'enquiry_id' => $owner instanceof Enquiry ? $owner->id : $owner->enquiry_id,
            'client_id' => $owner->client_id,
            'category' => $deliverable ? 'deliverable' : $meta['category'],
            'title' => trim($meta['title']),
            'is_deliverable' => $deliverable,
            'status' => $deliverable ? DocumentStatus::Draft : DocumentStatus::Filed,
            'created_by' => $actor->id,
        ], $file, $actor, false, $meta['note'] ?? null);
    }

    /** Signed paper terms returned by the client, filed as evidence for an offline acceptance. */
    public function storeEvidence(int $clientId, ?int $enquiryId, UploadedFile $file, string $title, User $actor): Document
    {
        return $this->create([
            'enquiry_id' => $enquiryId,
            'client_id' => $clientId,
            'category' => 'engagement',
            'title' => $title,
            'status' => DocumentStatus::Filed,
            'created_by' => $actor->id,
        ], $file, $actor, false, 'Signed engagement terms returned by the client');
    }

    /** A new version. For a deliverable it goes back to draft; the client keeps the released version. */
    public function addVersion(Document $document, UploadedFile $file, ?string $note, User $actor): DocumentVersion
    {
        Gate::forUser($actor)->authorize('update', $document);
        if ($document->uploaded_by_client) {
            throw new RuleViolation('Client uploads cannot be replaced by staff. Upload the firm\'s copy as a separate document.');
        }

        [$version] = $this->storeVersion($document, $file, $actor, false, $note, function (Document $document) {
            if ($document->is_deliverable) {
                $document->status = DocumentStatus::Draft;
            } elseif ($document->status === DocumentStatus::Released) {
                $document->status = DocumentStatus::Filed;
            }
        });

        return $version;
    }

    public function submitForReview(Document $document, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $document);
        $this->assertDeliverable($document, [DocumentStatus::Draft, DocumentStatus::ChangesRequested], 'Only a draft can be sent for review.');
        $this->transition($document, DocumentStatus::InReview, 'submitted_for_review', null, $actor);
        $this->contacts->alertStaff(null, $document->matter, "Draft ready for review on {$document->matter->reference}",
            "\"{$document->title}\" is ready for internal review.", "/admin/matters/{$document->matter_id}");
    }

    /** Internal approval of the current version by a lawyer on the team or a full administrator. */
    public function approve(Document $document, ?string $note, User $actor): void
    {
        Gate::forUser($actor)->authorize('approve', $document);
        $this->assertDeliverable($document, [DocumentStatus::InReview], 'Only a draft in review can be approved.');
        $this->transition($document, DocumentStatus::Approved, 'approved', $note, $actor);
    }

    public function requestChanges(Document $document, string $note, User $actor): void
    {
        Gate::forUser($actor)->authorize('approve', $document);
        $this->assertDeliverable($document, [DocumentStatus::InReview], 'Only a draft in review can be sent back.');
        if (mb_strlen(trim($note)) < 5) {
            throw new RuleViolation('Explain what needs to change.');
        }
        $this->transition($document, DocumentStatus::ChangesRequested, 'internal_changes_requested', $note, $actor);
    }

    /** Shares the current version with the client. Deliverables must be approved first. */
    public function release(Document $document, ?string $note, User $actor, bool $notifyClient = true): void
    {
        Gate::forUser($actor)->authorize('update', $document);
        if (! $document->matter) {
            throw new RuleViolation('Only matter documents can be shared with the client.');
        }
        if ($document->is_deliverable && $document->status !== DocumentStatus::Approved) {
            throw new RuleViolation('A draft must be approved by a lawyer before release.');
        }
        if (! $document->is_deliverable && $document->status !== DocumentStatus::Filed) {
            throw new RuleViolation('This version is already shared with the client.');
        }

        DB::transaction(function () use ($document, $note, $actor) {
            $document->forceFill([
                'status' => DocumentStatus::Released,
                'audience' => 'client',
                'released_version_id' => $document->current_version_id,
                'released_at' => now(),
                'released_by' => $actor->id,
                'client_decision' => null,
                'client_decision_at' => null,
            ])->save();
            $this->event($document, 'released', $note ? trim($note) : 'Shared with the client.', true, $actor);
            $this->matters->event($document->matter, 'document_released', "Document shared: {$document->title}", true, $actor, ['document_id' => $document->id]);
            Audit::record('document.released', "{$document->matter->reference}: \"{$document->title}\" v{$document->currentVersion->version} released to client", $document, actor: $actor);
        });

        $notifyClient && $this->contacts->notify($document->matter->client, "A document is ready on {$document->matter->reference}",
            'Maestro Touch Legal has shared a document with you.', "/portal/matters/{$document->matter_id}");
    }

    /** Stops sharing a document released by mistake. It does not recall copies already downloaded. */
    public function withdrawFromClient(Document $document, string $reason, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $document);
        if (! $document->released_version_id || $document->uploaded_by_client) {
            throw new RuleViolation('This document is not shared by the firm.');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new RuleViolation('Record why the document is being withdrawn.');
        }

        DB::transaction(function () use ($document, $reason, $actor) {
            $document->forceFill([
                'status' => $document->is_deliverable ? DocumentStatus::Draft : DocumentStatus::Filed,
                'audience' => 'internal',
                'released_version_id' => null,
                'released_at' => null,
                'released_by' => null,
            ])->save();
            $this->event($document, 'withdrawn', trim($reason), false, $actor);
            Audit::record('document.withdrawn', "\"{$document->title}\" withdrawn from the client: ".trim($reason), $document, actor: $actor);
        });
    }

    public function requestFromClient(Matter $matter, string $title, ?string $description, ?string $dueOn, User $actor): DocumentRequest
    {
        Gate::forUser($actor)->authorize('manageDocuments', $matter);
        if (trim($title) === '') {
            throw new RuleViolation('Say which document you need.');
        }
        $request = $matter->documentRequests()->create([
            'title' => trim($title),
            'description' => filled($description) ? trim($description) : null,
            'due_on' => $dueOn ?: null,
            'status' => 'open',
            'requested_by' => $actor->id,
        ]);
        $this->matters->event($matter, 'document_requested', "Document requested from you: {$request->title}", true, $actor, ['request_id' => $request->id]);
        Audit::record('document_request.created', "{$matter->reference}: requested \"{$request->title}\" from client", $matter, actor: $actor);
        $this->contacts->notify($matter->client, "Document requested on {$matter->reference}", 'The firm has asked you for a document.', "/portal/matters/{$matter->id}");

        return $request;
    }

    public function cancelRequest(DocumentRequest $request, string $reason, User $actor): void
    {
        Gate::forUser($actor)->authorize('manageDocuments', $request->matter);
        if ($request->status !== 'open') {
            throw new RuleViolation('This request is no longer open.');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new RuleViolation('Record why the request is cancelled.');
        }
        $request->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $this->matters->event($request->matter, 'document_request_cancelled', "Document request cancelled: {$request->title}", true, $actor);
        Audit::record('document_request.cancelled', "Request \"{$request->title}\" cancelled: ".trim($reason), $request->matter, actor: $actor);
    }

    /** A client contact uploads to their own matter, optionally answering an open request. */
    public function clientUpload(Matter $matter, UploadedFile $file, string $title, ?int $requestId, ?string $note, User $client, bool $alertStaff = true): Document
    {
        Gate::forUser($client)->authorize('actAsClient', $matter);
        if ($matter->isClosed()) {
            throw new RuleViolation('This matter is closed. Please contact the firm.');
        }
        $request = null;
        if ($requestId) {
            $request = $matter->documentRequests()->open()->whereKey($requestId)->first();
            if (! $request) {
                throw new RuleViolation('That document request is no longer open.');
            }
        }
        $title = trim($title) !== '' ? trim($title) : ($request?->title ?? 'Uploaded document');

        $document = $this->create([
            'matter_id' => $matter->id,
            'client_id' => $matter->client_id,
            'document_request_id' => $request?->id,
            'category' => 'client_supplied',
            'title' => mb_substr($title, 0, 255),
            'audience' => 'client',
            'status' => DocumentStatus::Filed,
            'uploaded_by_client' => true,
            'created_by' => $client->id,
        ], $file, $client, true, $note, function (Document $document) use ($request, $matter, $client) {
            $request?->update(['status' => 'fulfilled', 'fulfilled_at' => now()]);
            $this->matters->event($matter, 'client_uploaded', "You uploaded: {$document->title}", true, $client, ['document_id' => $document->id]);
        });

        $alertStaff && $this->contacts->alertStaff(null, $matter, "Client uploaded a document on {$matter->reference}", 'The client has uploaded a document.', "/admin/matters/{$matter->id}");

        return $document;
    }

    /**
     * The client's own approval of, or request for changes to, the exact released version they viewed.
     */
    public function clientDecision(Document $document, int $versionId, string $decision, ?string $comment, User $client, ?string $ip, ?string $userAgent): Acceptance
    {
        Gate::forUser($client)->authorize('decideAsClient', $document);
        if (! in_array($decision, ['approved', 'changes_requested'], true)) {
            throw new RuleViolation('Choose to approve or request changes.');
        }
        if ($decision === 'changes_requested' && mb_strlen(trim((string) $comment)) < 5) {
            throw new RuleViolation('Tell the firm what you would like changed.');
        }

        $acceptance = DB::transaction(function () use ($document, $versionId, $decision, $comment, $client, $ip, $userAgent) {
            $document = Document::lockForUpdate()->findOrFail($document->id);
            $version = $document->releasedVersion;
            if (! $version || $version->id !== $versionId) {
                throw new RuleViolation('This document has changed since you opened it. Please review the latest version.');
            }
            if ($document->client_decision !== null) {
                throw new RuleViolation('You have already responded to this version.');
            }

            $statement = strtr($decision === 'approved' ? self::CLIENT_APPROVE_STATEMENT : self::CLIENT_CHANGES_STATEMENT, [
                ':version' => $version->version,
                ':title' => $document->title,
                ':date' => $document->released_at?->timezone(config('app.firm_timezone'))->format('j F Y'),
            ]);
            $acceptance = Acceptance::create([
                'acceptable_type' => $version->getMorphClass(),
                'acceptable_id' => $version->id,
                'decision' => $decision,
                'method' => 'portal',
                'client_id' => $document->client_id ?? $document->matter->client_id,
                'user_id' => $client->id,
                'signed_name' => $client->name,
                'content_hash' => $version->sha256,
                'statement' => $statement,
                'comment' => filled($comment) ? trim($comment) : null,
                'ip' => $ip,
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
                'created_at' => now(),
            ]);
            $document->forceFill(['client_decision' => $decision, 'client_decision_at' => now()])->save();
            $label = $decision === 'approved' ? 'approved' : 'requested changes to';
            $this->event($document, 'client_'.$decision, filled($comment) ? trim($comment) : null, true, $client, $version->id);
            $this->matters->event($document->matter, 'client_'.$decision, "You {$label} \"{$document->title}\" (version {$version->version}).", true, $client);
            Audit::record('document.client_'.$decision, "Client {$label} \"{$document->title}\" v{$version->version}", $document,
                ['after' => ['version' => $version->version, 'sha256' => $version->sha256]], actor: $client);

            return $acceptance;
        });

        $this->contacts->alertStaff(null, $document->matter, "Client responded to a draft on {$document->matter->reference}",
            'The client has responded to a released draft.', "/admin/matters/{$document->matter_id}");

        return $acceptance;
    }

    /** Recorded for every staff or client download/preview. */
    public function recordAccess(DocumentVersion $version, User $user, bool $inline): void
    {
        $document = $version->document;
        $this->event($document, $inline ? 'previewed' : 'downloaded', null, false, $user, $version->id);
        Audit::record($inline ? 'document.previewed' : 'document.downloaded', "\"{$document->title}\" v{$version->version} ".($inline ? 'previewed' : 'downloaded'), $document,
            context: ['version_id' => $version->id, 'as_client' => $user->isClient() && ! $user->isStaff()], actor: $user);
    }

    private function create(array $attributes, UploadedFile $file, User $actor, bool $byClient, ?string $note, ?callable $inside = null): Document
    {
        $checked = UploadGuard::check($file);
        $path = $this->put($file, $checked['extension']);

        try {
            return DB::transaction(function () use ($attributes, $checked, $path, $actor, $byClient, $note, $inside) {
                $document = Document::create($attributes + ['audience' => 'internal']);
                $version = $this->versionRecord($document, 1, $checked, $path, $actor, $byClient, $note);
                $document->forceFill(['current_version_id' => $version->id])->save();
                $this->event($document, 'uploaded', $note ? trim($note) : null, $byClient, $actor, $version->id);
                Audit::record('document.uploaded', "\"{$document->title}\" uploaded ({$checked['mime']}, {$checked['size']} bytes)", $document,
                    context: ['sha256' => $checked['sha256'], 'by_client' => $byClient], actor: $actor);
                if ($inside) {
                    $inside($document);
                }

                return $document;
            });
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($path);
            throw $e;
        }
    }

    /** @return array{0: DocumentVersion} */
    private function storeVersion(Document $document, UploadedFile $file, User $actor, bool $byClient, ?string $note, callable $mutate): array
    {
        $checked = UploadGuard::check($file);
        $path = $this->put($file, $checked['extension']);

        try {
            return DB::transaction(function () use ($document, $checked, $path, $actor, $byClient, $note, $mutate) {
                $document = Document::lockForUpdate()->findOrFail($document->id);
                $next = ($document->versions()->max('version') ?? 0) + 1;
                $version = $this->versionRecord($document, $next, $checked, $path, $actor, $byClient, $note);
                $document->current_version_id = $version->id;
                $mutate($document);
                $document->save();
                $this->event($document, 'version_added', $note ? trim($note) : null, false, $actor, $version->id);
                Audit::record('document.version_added', "\"{$document->title}\" version {$next} uploaded", $document, context: ['sha256' => $checked['sha256']], actor: $actor);

                return [$version];
            });
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($path);
            throw $e;
        }
    }

    private function versionRecord(Document $document, int $number, array $checked, string $path, User $actor, bool $byClient, ?string $note): DocumentVersion
    {
        return DocumentVersion::create([
            'document_id' => $document->id,
            'version' => $number,
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => $checked['original_name'],
            'mime_type' => $checked['mime'],
            'size' => $checked['size'],
            'sha256' => $checked['sha256'],
            'uploaded_by' => $actor->id,
            'uploaded_by_client' => $byClient,
            'note' => filled($note) ? trim($note) : null,
            'created_at' => now(),
        ]);
    }

    /** Random stored name; the original name is kept only as metadata. */
    private function put(UploadedFile $file, string $extension): string
    {
        $name = Str::uuid()->toString().'.'.$extension;
        $path = Storage::disk(self::DISK)->putFileAs('documents/'.now()->format('Y/m'), $file, $name);
        if (! $path) {
            throw new RuleViolation('The file could not be stored. Please try again.');
        }

        return $path;
    }

    private function transition(Document $document, DocumentStatus $to, string $type, ?string $note, User $actor): void
    {
        DB::transaction(function () use ($document, $to, $type, $note, $actor) {
            $from = $document->status;
            $document->forceFill(['status' => $to])->save();
            $this->event($document, $type, filled($note) ? trim($note) : null, false, $actor, $document->current_version_id);
            Audit::record('document.'.$type, "\"{$document->title}\": {$from->label()} → {$to->label()}", $document, actor: $actor);
        });
    }

    private function assertDeliverable(Document $document, array $from, string $message): void
    {
        if (! $document->is_deliverable || ! in_array($document->status, $from, true)) {
            throw new RuleViolation($message);
        }
    }

    private function assertMeta(array $meta): void
    {
        if (trim((string) ($meta['title'] ?? '')) === '') {
            throw new RuleViolation('Give the document a title.');
        }
        if (! isset(Document::CATEGORIES[$meta['category'] ?? ''])) {
            throw new RuleViolation('Choose a document category.');
        }
    }

    private function event(Document $document, string $type, ?string $body, bool $clientVisible, ?User $actor, ?int $versionId = null): DocumentEvent
    {
        return DocumentEvent::create([
            'document_id' => $document->id,
            'document_version_id' => $versionId,
            'type' => $type,
            'body' => $body,
            'client_visible' => $clientVisible,
            'actor_id' => $actor?->id,
            'created_at' => now(),
        ]);
    }
}
