<?php

namespace App\Domain\Identity;

use App\Domain\Operations\Audit;
use App\Models\Invitation;
use App\Models\StaffApplication;
use App\Models\User;
use App\Notifications\InvitationIssued;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Staff (and later client-contact) invitations. Only the SHA-256 hash of a token
 * is stored; the plaintext exists only in the emailed link.
 */
class Invitations
{
    public const TTL_DAYS = 7;

    public function __construct(private AccountAdministration $accounts) {}

    /**
     * @param  list<Role>  $roles
     * @return array{0: Invitation, 1: string} the invitation and its plaintext token
     */
    public function issue(string $email, string $name, array $roles, ?User $actor, ?StaffApplication $application = null, bool $send = true): array
    {
        if ($actor && ! $actor->isFullAdministrator()) {
            throw new AccountAdministrationException('Only a Technical Administrator or Firm Principal can invite staff.');
        }
        if ($roles === []) {
            throw new AccountAdministrationException('Select at least one role for the invitation.');
        }

        $email = Str::lower(trim($email));
        $token = Str::random(48);

        $invitation = DB::transaction(function () use ($email, $name, $roles, $actor, $application, $token) {
            // Only one live invitation per address.
            Invitation::pending()->where('email', $email)
                ->update(['revoked_at' => now(), 'revoked_by' => $actor?->id]);

            $invitation = Invitation::create([
                'email' => $email,
                'name' => $name,
                'roles' => array_map(fn (Role $r) => $r->value, $roles),
                'token_hash' => Invitation::hashToken($token),
                'invited_by' => $actor?->id,
                'staff_application_id' => $application?->id,
                'expires_at' => now()->addDays(self::TTL_DAYS),
            ]);

            Audit::record('invitation.issued', "Invited {$email} as ".implode(', ', array_map(fn ($r) => $r->label(), $roles)),
                $invitation, ['after' => ['email' => $email, 'roles' => $invitation->roles]], actor: $actor);

            return $invitation;
        });

        if ($send) {
            $this->send($invitation, $token);
        }

        return [$invitation, $token];
    }

    /** Resending rotates the token, so any older link stops working. */
    public function resend(Invitation $invitation, User $actor): void
    {
        if (! $actor->isFullAdministrator()) {
            throw new AccountAdministrationException('Only a Technical Administrator or Firm Principal can resend invitations.');
        }
        if ($invitation->accepted_at || $invitation->revoked_at) {
            throw new AccountAdministrationException('This invitation is no longer open.');
        }

        $token = Str::random(48);
        $invitation->forceFill([
            'token_hash' => Invitation::hashToken($token),
            'expires_at' => now()->addDays(self::TTL_DAYS),
        ])->save();

        Audit::record('invitation.resent', "Resent invitation to {$invitation->email}", $invitation, actor: $actor);
        $this->send($invitation, $token);
    }

    public function revoke(Invitation $invitation, User $actor): void
    {
        if (! $actor->isFullAdministrator()) {
            throw new AccountAdministrationException('Only a Technical Administrator or Firm Principal can revoke invitations.');
        }
        if ($invitation->accepted_at || $invitation->revoked_at) {
            throw new AccountAdministrationException('This invitation is no longer open.');
        }

        $invitation->forceFill(['revoked_at' => now(), 'revoked_by' => $actor->id])->save();
        Audit::record('invitation.revoked', "Revoked invitation to {$invitation->email}", $invitation, actor: $actor);
    }

    /**
     * Accept as a new account. Existing accounts accept via acceptExisting() after signing in.
     */
    public function acceptNew(Invitation $invitation, string $name, string $password): User
    {
        return DB::transaction(function () use ($invitation, $name, $password) {
            $invitation = Invitation::lockForUpdate()->findOrFail($invitation->id);
            $this->assertUsable($invitation);

            if (User::where('email', $invitation->email)->exists()) {
                throw ValidationException::withMessages(['email' => 'An account already exists for this address. Sign in to accept the invitation.']);
            }

            $user = new User(['name' => $name, 'email' => $invitation->email]);
            $user->password = Hash::make($password);
            // The invitee proved control of the mailbox by opening the emailed link.
            $user->email_verified_at = now();
            $user->save();

            Audit::record('user.created', "Account created for {$user->email} from invitation", $user, actor: $user);

            return $this->complete($invitation, $user);
        });
    }

    public function acceptExisting(Invitation $invitation, User $user): User
    {
        return DB::transaction(function () use ($invitation, $user) {
            $invitation = Invitation::lockForUpdate()->findOrFail($invitation->id);
            $this->assertUsable($invitation);

            if (Str::lower($user->email) !== $invitation->email) {
                throw new AccountAdministrationException('This invitation was sent to a different email address.');
            }
            if ($user->offboarded_at || $user->suspended_at) {
                throw new AccountAdministrationException('This account is not active. Contact the firm.');
            }

            return $this->complete($invitation, $user);
        });
    }

    private function complete(Invitation $invitation, User $user): User
    {
        $inviter = $invitation->invitedBy;

        foreach ($invitation->roles as $value) {
            $this->accounts->grantRoleFromInvitation($user, Role::from($value), $inviter);
        }

        if ($user->isStaff()) {
            $user->staffProfile()->firstOrCreate([]);
        }

        $invitation->forceFill(['accepted_at' => now(), 'accepted_user_id' => $user->id])->save();
        Audit::record('invitation.accepted', "{$user->email} accepted an invitation", $invitation, actor: $user);

        return $user;
    }

    private function assertUsable(Invitation $invitation): void
    {
        if (! $invitation->isUsable()) {
            throw new AccountAdministrationException('This invitation has expired or is no longer valid. Ask the firm to send a new one.');
        }
    }

    private function send(Invitation $invitation, string $token): void
    {
        Notification::route('mail', [$invitation->email => $invitation->name])
            ->notify(new InvitationIssued($invitation, $token));

        $invitation->forceFill(['last_sent_at' => now(), 'send_count' => $invitation->send_count + 1])->save();
    }
}
