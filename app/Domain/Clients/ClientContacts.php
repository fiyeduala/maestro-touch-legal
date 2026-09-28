<?php

namespace App\Domain\Clients;

use App\Domain\Identity\Invitations;
use App\Domain\Identity\Role;
use App\Domain\Operations\Audit;
use App\Domain\Operations\StaffNotifier;
use App\Domain\RuleViolation;
use App\Models\Client;
use App\Models\Enquiry;
use App\Models\Invitation;
use App\Models\Matter;
use App\Models\User;
use App\Notifications\PortalUpdate;
use App\Notifications\StaffAlert;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/** Who is told about client-facing events, and how portal contacts are added (by invitation only). */
class ClientContacts
{
    public function __construct(private Invitations $invitations) {}

    /** Active, verified portal users who are current contacts for the client. */
    public function portalUsers(Client $client): Collection
    {
        return $client->users()->active()->withActiveRole(Role::Client)->whereNotNull('email_verified_at')->get();
    }

    public function notify(Client $client, string $subject, string $line, string $path): void
    {
        $users = $this->portalUsers($client);
        if ($users->isEmpty()) {
            Log::info("Client {$client->reference} has no active portal contact; portal notification not emailed.");

            return;
        }
        Notification::send($users, new PortalUpdate($subject, $line, $path));
    }

    /** Matter team (or the enquiry owner); falls back to full administrators when nobody is assigned. */
    public function alertStaff(?Enquiry $enquiry, ?Matter $matter, string $subject, string $line, string $path): void
    {
        $recipients = collect();
        if ($matter) {
            $recipients = $matter->activeTeam()->with('user')->get()->pluck('user');
        }
        if ($enquiry?->owner) {
            $recipients->push($enquiry->owner);
        }
        $recipients = $recipients->filter(fn (?User $u) => $u?->isActive());

        $alert = new StaffAlert($subject, $line, $path);
        $recipients->isEmpty()
            ? StaffNotifier::administrators($alert, 'Client activity')
            : StaffNotifier::users($recipients, $alert);
    }

    /**
     * Invites a person to access the client's portal. They set their own password when accepting;
     * access is granted only after acceptance (Invitations::complete links them to the client).
     *
     * @return array{0: Invitation, 1: string}
     */
    public function invite(Client $client, string $email, string $name, User $actor): array
    {
        Gate::forUser($actor)->authorize('manageContacts', $client);
        $existing = User::where('email', mb_strtolower(trim($email)))->first();
        if ($existing?->isStaff()) {
            throw new RuleViolation('That address belongs to a staff account. Use a separate address for client portal access.');
        }
        if ($existing && $client->users()->whereKey($existing->id)->exists()) {
            throw new RuleViolation('That person is already a portal contact for this client.');
        }

        return $this->invitations->issue($email, $name, [Role::Client], $actor, client: $client);
    }

    /** Revoking removes portal access to this client's records from the next request. */
    public function revoke(Client $client, User $contact, User $actor, string $reason): void
    {
        Gate::forUser($actor)->authorize('manageContacts', $client);
        if (mb_strlen(trim($reason)) < 5) {
            throw new RuleViolation('Record why this contact\'s access is being removed.');
        }

        DB::transaction(function () use ($client, $contact, $actor, $reason) {
            $updated = DB::table('client_user')->where('client_id', $client->id)->where('user_id', $contact->id)->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoked_by' => $actor->id, 'updated_at' => now()]);
            if (! $updated) {
                throw new RuleViolation('That person is not a current contact for this client.');
            }
            Audit::record('client.contact_revoked', "Portal access to {$client->reference} removed for {$contact->email}: ".trim($reason), $client,
                ['before' => ['user_id' => $contact->id]], actor: $actor);
        });
    }
}
