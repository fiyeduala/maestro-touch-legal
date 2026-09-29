<?php

namespace App\Domain\Notarisation;

use App\Domain\Matters\Matters;
use App\Domain\Operations\Audit;
use App\Domain\RuleViolation;
use App\Models\Matter;
use App\Models\NotarisationHandoff;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Manual handoffs to Naija Virtual Notary (DECISIONS D40). Staff record the client's consent, pass the document
 * to NVN themselves, and keep the status and NVN's reference up to date here. There is no NVN API: nothing is
 * sent, no NVN account is created and no files or credentials are shared from this system.
 *
 * Adapter boundary: if NVN later offers an API, a gateway would be called from record() (to submit, returning
 * the external reference) and a scheduled check would call updateStatus() with the status NVN reports. The
 * consent rule, status transitions, matter timeline and audit stay here, unchanged.
 */
class NotarisationHandoffs
{
    public function __construct(private Matters $matters) {}

    /** @param  array{document_description?: string, consent_method?: string, consent_note?: ?string, consent_confirmed?: bool}  $data */
    public function record(Matter $matter, array $data, User $actor): NotarisationHandoff
    {
        Gate::forUser($actor)->authorize('update', $matter);
        if ($matter->isClosed()) {
            throw new RuleViolation('This matter is closed. Reopen it before arranging notarisation.');
        }
        if (! ($data['consent_confirmed'] ?? false)) {
            throw new RuleViolation("Record the client's consent before handing anything to Naija Virtual Notary.");
        }
        $description = trim((string) ($data['document_description'] ?? ''));
        $method = (string) ($data['consent_method'] ?? '');
        if ($description === '' || ! isset(NotarisationHandoff::CONSENT_METHODS[$method])) {
            throw new RuleViolation('Describe the document and say how the client gave consent.');
        }

        return DB::transaction(function () use ($matter, $description, $method, $data, $actor) {
            $handoff = $matter->notarisationHandoffs()->create([
                'document_description' => mb_substr($description, 0, 500),
                'consent_method' => $method,
                'consent_note' => filled($data['consent_note'] ?? null) ? mb_substr(trim($data['consent_note']), 0, 500) : null,
                'consent_recorded_at' => now(),
                'consent_recorded_by' => $actor->id,
                'status' => 'consented',
                'status_changed_at' => now(),
                'status_changed_by' => $actor->id,
            ]);
            $this->matters->event($matter, 'notarisation', "Notarisation arranged with Naija Virtual Notary: {$handoff->document_description}", true, $actor);
            Audit::record('notarisation.consent_recorded', "{$matter->reference}: client consent recorded for NVN notarisation of '{$handoff->document_description}'",
                $matter, context: ['handoff_id' => $handoff->id, 'consent_method' => $method], actor: $actor);

            return $handoff;
        });
    }

    public function updateStatus(NotarisationHandoff $handoff, string $status, ?string $reference, ?string $note, User $actor): void
    {
        $matter = $handoff->matter;
        Gate::forUser($actor)->authorize('update', $matter);
        $reference = filled($reference) ? trim($reference) : $handoff->external_reference;
        if ($reference !== null && ! preg_match('/^[A-Za-z0-9._\/\- ]{1,100}$/', $reference)) {
            throw new RuleViolation('The NVN reference may contain letters, numbers, spaces and . _ / - only.');
        }
        if (! isset(NotarisationHandoff::STATUSES[$status])
            || ($status !== $handoff->status && ! in_array($status, NotarisationHandoff::NEXT[$handoff->status] ?? [], true))) {
            throw new RuleViolation('That status change is not allowed from "'.NotarisationHandoff::STATUSES[$handoff->status].'".');
        }
        if ($status === 'completed' && $reference === null) {
            throw new RuleViolation('Enter the NVN reference before marking the document notarised.');
        }
        if ($status === 'cancelled' && blank($note)) {
            throw new RuleViolation('Give a reason for cancelling.');
        }

        DB::transaction(function () use ($handoff, $matter, $status, $reference, $note, $actor) {
            $before = $handoff->only(['status', 'external_reference']);
            $handoff->forceFill([
                'status' => $status,
                'external_reference' => $reference,
                'status_note' => filled($note) ? mb_substr(trim($note), 0, 500) : $handoff->status_note,
                'status_changed_at' => $status !== $handoff->status ? now() : $handoff->status_changed_at,
                'status_changed_by' => $actor->id,
            ])->save();

            if ($before['status'] !== $status) {
                $this->matters->event($matter, 'notarisation', 'Notarisation – '.NotarisationHandoff::STATUSES[$status].": {$handoff->document_description}", true, $actor);
            }
            Audit::record('notarisation.updated', "{$matter->reference}: NVN handoff #{$handoff->id} ".NotarisationHandoff::STATUSES[$status], $matter,
                ['before' => $before, 'after' => $handoff->only(['status', 'external_reference'])], actor: $actor);
        });
    }
}
