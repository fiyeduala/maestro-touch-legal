<?php

namespace App\Domain\Identity;

use App\Domain\Operations\Audit;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Role grants, suspension, offboarding and session revocation.
 *
 * Invariant: the firm always keeps at least one active full administrator
 * (Technical Administrator or Firm Principal). Any change that would remove
 * the last one is refused. Callers in the UI must re-confirm the actor's
 * password before invoking the privileged methods here.
 */
class AccountAdministration
{
    public function grantRole(User $user, Role $role, User $actor): void
    {
        $this->authorise($actor, $role);

        DB::transaction(function () use ($user, $role, $actor) {
            if ($user->activeRoleGrants()->where('role', $role->value)->lockForUpdate()->exists()) {
                return;
            }

            UserRole::create([
                'user_id' => $user->id,
                'role' => $role,
                'granted_by' => $actor->id,
                'granted_at' => now(),
            ]);
            $user->flushRoleCache();

            Audit::record('user.role_granted', "Granted {$role->label()} to {$user->email}", $user,
                ['after' => ['role' => $role->value]], actor: $actor);
        });
    }

    /** Grants a role recorded on an invitation that an administrator issued earlier. */
    public function grantRoleFromInvitation(User $user, Role $role, ?User $inviter): void
    {
        DB::transaction(function () use ($user, $role, $inviter) {
            if ($user->activeRoleGrants()->where('role', $role->value)->exists()) {
                return;
            }

            UserRole::create([
                'user_id' => $user->id,
                'role' => $role,
                'granted_by' => $inviter?->id,
                'granted_at' => now(),
            ]);
            $user->flushRoleCache();

            Audit::record('user.role_granted', "Granted {$role->label()} to {$user->email} by invitation", $user,
                ['after' => ['role' => $role->value]], ['via' => 'invitation'], $user);
        });
    }

    public function revokeRole(User $user, Role $role, User $actor, ?string $reason = null): void
    {
        $this->authorise($actor, $role);

        DB::transaction(function () use ($user, $role, $actor, $reason) {
            $grant = $user->activeRoleGrants()->where('role', $role->value)->lockForUpdate()->first();
            if (! $grant) {
                return;
            }

            if ($role->isFullAdministrator()) {
                $this->guardLastAdministrator($user, afterRemovingRole: $role);
            }

            $grant->update(['revoked_by' => $actor->id, 'revoked_at' => now(), 'revocation_reason' => $reason]);
            $user->flushRoleCache();

            if (! $user->isStaff()) {
                $this->revokeSessions($user, $actor, 'staff roles removed');
            }

            Audit::record('user.role_revoked', "Revoked {$role->label()} from {$user->email}", $user,
                ['before' => ['role' => $role->value]], ['reason' => $reason], $actor);
        });
    }

    /**
     * Replace the user's staff roles with exactly $roles (client role untouched).
     *
     * @param  list<Role>  $roles
     */
    public function syncStaffRoles(User $user, array $roles, User $actor, ?string $reason = null): void
    {
        DB::transaction(function () use ($user, $roles, $actor, $reason) {
            $wanted = collect($roles)->filter(fn (Role $r) => $r->isStaff())->unique(fn ($r) => $r->value);
            $current = collect($user->roles())->filter(fn (Role $r) => $r->isStaff());

            foreach ($wanted as $role) {
                if (! $current->contains($role)) {
                    $this->grantRole($user, $role, $actor);
                }
            }
            foreach ($current as $role) {
                if (! $wanted->contains($role)) {
                    $this->revokeRole($user, $role, $actor, $reason);
                }
            }
        });
    }

    public function suspend(User $user, User $actor, string $reason): void
    {
        $this->authorise($actor);
        if ($user->is($actor)) {
            throw new AccountAdministrationException('You cannot suspend your own account.');
        }

        DB::transaction(function () use ($user, $actor, $reason) {
            $user = User::lockForUpdate()->findOrFail($user->id);
            if ($user->isSuspended()) {
                return;
            }
            $this->guardLastAdministrator($user);

            $user->forceFill([
                'suspended_at' => now(),
                'suspended_by' => $actor->id,
                'suspension_reason' => $reason,
            ])->save();

            $this->revokeSessions($user, $actor, 'suspended');
            Audit::record('user.suspended', "Suspended {$user->email}", $user, null, ['reason' => $reason], $actor);
        });
    }

    public function reinstate(User $user, User $actor): void
    {
        $this->authorise($actor);

        $user->forceFill(['suspended_at' => null, 'suspended_by' => null, 'suspension_reason' => null])->save();
        Audit::record('user.reinstated', "Reinstated {$user->email}", $user, actor: $actor);
    }

    /**
     * Offboarding removes every staff role and ends all sessions. The account and
     * its history are kept for the record; client access, if any, is untouched.
     */
    public function offboard(User $user, User $actor, string $reason): void
    {
        $this->authorise($actor);
        if ($user->is($actor)) {
            throw new AccountAdministrationException('You cannot offboard your own account.');
        }

        DB::transaction(function () use ($user, $actor, $reason) {
            $this->guardLastAdministrator($user);

            foreach ($user->roles() as $role) {
                if ($role->isStaff()) {
                    $user->activeRoleGrants()->where('role', $role->value)
                        ->update(['revoked_by' => $actor->id, 'revoked_at' => now(), 'revocation_reason' => 'Offboarded: '.$reason]);
                }
            }
            $user->flushRoleCache();
            $user->forceFill(['offboarded_at' => now()])->save();

            $this->revokeSessions($user, $actor, 'offboarded');
            Audit::record('user.offboarded', "Offboarded {$user->email}", $user, null, ['reason' => $reason], $actor);
        });
    }

    /** Ends every session for the user (database sessions + remember-me cookies). */
    public function revokeSessions(User $user, ?User $actor = null, string $why = 'revoked'): int
    {
        $count = DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();

        Audit::record('user.sessions_revoked', "Ended {$count} session(s) for {$user->email} ({$why})", $user,
            null, ['sessions' => $count], $actor);

        return $count;
    }

    private function authorise(User $actor, ?Role $role = null): void
    {
        if (! $actor->isFullAdministrator()) {
            throw new AccountAdministrationException('Only a Technical Administrator or Firm Principal can manage accounts and roles.');
        }
    }

    private function guardLastAdministrator(User $user, ?Role $afterRemovingRole = null): void
    {
        if (! $user->isFullAdministrator()) {
            return;
        }

        $remainingOwnAdminRoles = collect($user->roles())
            ->filter(fn (Role $r) => $r->isFullAdministrator() && $r !== $afterRemovingRole);
        if ($afterRemovingRole && $remainingOwnAdminRoles->isNotEmpty()) {
            return;
        }

        $others = User::query()->active()
            ->whereKeyNot($user->id)
            ->withActiveRole(...Role::fullAdministratorRoles())
            ->lockForUpdate()
            ->count();

        if ($others === 0) {
            throw new AccountAdministrationException('This is the last active full administrator. Appoint another Technical Administrator or Firm Principal first.');
        }
    }
}
