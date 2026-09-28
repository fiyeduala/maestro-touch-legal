<?php

namespace App\Domain\Intake;

use App\Domain\Operations\Audit;
use App\Domain\RuleViolation;
use App\Models\Client;
use App\Models\ConflictReview;
use App\Models\Enquiry;
use App\Models\Party;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Conflict checks (spec §6). The system only offers suggestions; an authorised person must clear or
 * flag the enquiry with a reason. No match is never treated as clearance.
 *
 * Suggestions are deliberately limited: a name, the role it was recorded in and a reference, never
 * matter details, so a reviewer who is not on another matter learns only that a possible match exists.
 */
class ConflictChecks
{
    public const DECISIONS = ['cleared' => 'Cleared – no conflict found', 'flagged' => 'Flagged – possible conflict'];

    private const MAX_SUGGESTIONS = 50;

    /** @return list<string> the normalised names searched for this enquiry */
    public function searchTerms(Enquiry $enquiry): array
    {
        $names = $enquiry->parties()->pluck('name')->all();
        $names[] = $enquiry->contact_name;
        if ($enquiry->organisation_name) {
            $names[] = $enquiry->organisation_name;
        }

        return array_values(array_unique(array_filter(array_map(fn ($n) => Party::normalize((string) $n), $names))));
    }

    /**
     * @return list<array{name: string, role: string, reference: string, source: string, match: string}>
     */
    public function suggestions(Enquiry $enquiry, User $viewer): array
    {
        Gate::forUser($viewer)->authorize('view', $enquiry);

        $out = [];
        foreach ($this->searchTerms($enquiry) as $term) {
            $tokens = array_values(array_filter(explode(' ', $term), fn ($t) => mb_strlen($t) >= 3));

            $parties = Party::query()
                ->where(fn ($q) => $q->whereNull('enquiry_id')->orWhere('enquiry_id', '!=', $enquiry->id))
                ->where(function ($q) use ($term, $tokens) {
                    $q->where('normalized_name', $term);
                    foreach ($tokens as $token) {
                        $q->orWhere('normalized_name', 'like', '%'.addcslashes($token, '%_\\').'%');
                    }
                })
                ->with(['enquiry:id,reference', 'matter:id,reference'])
                ->limit(self::MAX_SUGGESTIONS)->get();

            foreach ($parties as $party) {
                $out[] = [
                    'name' => $party->name,
                    'role' => Party::ROLES[$party->role] ?? $party->role,
                    'reference' => $party->matter?->reference ?? $party->enquiry?->reference ?? '—',
                    'source' => $party->matter_id ? 'matter' : 'enquiry',
                    'match' => $party->normalized_name === $term ? 'exact' : 'partial',
                ];
            }

            $clients = Client::query()
                ->when($enquiry->client_id, fn ($q) => $q->whereKeyNot($enquiry->client_id))
                ->where(function ($q) use ($tokens) {
                    foreach ($tokens as $token) {
                        $like = '%'.addcslashes($token, '%_\\').'%';
                        $q->orWhere('display_name', 'like', $like)->orWhere('organisation_name', 'like', $like);
                    }
                })
                ->when($tokens === [], fn ($q) => $q->whereRaw('1 = 0'))
                ->limit(self::MAX_SUGGESTIONS)->get(['id', 'reference', 'display_name']);

            foreach ($clients as $client) {
                $out[] = [
                    'name' => $client->display_name,
                    'role' => 'Existing client',
                    'reference' => $client->reference,
                    'source' => 'client',
                    'match' => Party::normalize($client->display_name) === $term ? 'exact' : 'partial',
                ];
            }
        }

        return array_slice(array_values(array_unique($out, SORT_REGULAR)), 0, self::MAX_SUGGESTIONS);
    }

    public function decide(Enquiry $enquiry, string $decision, string $reason, User $reviewer): ConflictReview
    {
        Gate::forUser($reviewer)->authorize('decideConflict', $enquiry);
        if (! array_key_exists($decision, self::DECISIONS)) {
            throw new RuleViolation('Choose whether to clear or flag the conflict check.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw new RuleViolation('Record the reason for this decision (at least 10 characters), including what was checked.');
        }
        if (! $enquiry->status->isOpen()) {
            throw new RuleViolation('The enquiry is no longer open.');
        }
        if (! $enquiry->parties()->exists()) {
            throw new RuleViolation('Record at least one party before deciding the conflict check.');
        }

        $searched = $this->searchTerms($enquiry);
        $suggestions = $this->suggestions($enquiry, $reviewer);

        return DB::transaction(function () use ($enquiry, $decision, $reason, $reviewer, $searched, $suggestions) {
            $review = ConflictReview::create([
                'enquiry_id' => $enquiry->id,
                'decision' => $decision,
                'reason' => $reason,
                'searched' => $searched,
                'suggestions' => $suggestions,
                'reviewer_id' => $reviewer->id,
                'created_at' => now(),
            ]);
            $enquiry->forceFill(['conflict_status' => $decision, 'conflict_reviewed_at' => now()])->save();

            app(Enquiries::class)->event($enquiry, 'conflict_'.$decision, null, null, $reason, $reviewer);
            Audit::record('enquiry.conflict_'.$decision, "{$enquiry->reference}: conflict check {$decision} (".count($suggestions).' suggestion(s) shown)',
                $enquiry, ['after' => ['decision' => $decision, 'suggestions' => count($suggestions)]], actor: $reviewer);

            return $review;
        });
    }
}
