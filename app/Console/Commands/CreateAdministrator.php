<?php

namespace App\Console\Commands;

use App\Domain\Identity\Invitations;
use App\Domain\Identity\Role;
use App\Domain\Operations\Audit;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Bootstraps a full administrator without any seeded password: it issues an
 * invitation and prints the one-time acceptance link (or emails it with --send).
 */
class CreateAdministrator extends Command
{
    protected $signature = 'mtl:invite-admin
        {email : Address of the administrator}
        {name : Full name}
        {--role=technical_admin : technical_admin or firm_principal}
        {--send : Email the link instead of printing it}';

    protected $description = 'Issue a one-time invitation for a Technical Administrator or Firm Principal';

    public function handle(Invitations $invitations): int
    {
        $role = Role::tryFrom((string) $this->option('role'));
        if (! $role?->isFullAdministrator()) {
            $this->error('--role must be technical_admin or firm_principal.');

            return self::FAILURE;
        }

        $email = (string) $this->argument('email');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid email address.');

            return self::FAILURE;
        }

        $existing = User::where('email', strtolower($email))->first();
        if ($existing?->isFullAdministrator()) {
            $this->warn("{$email} is already an active full administrator.");

            return self::SUCCESS;
        }

        [$invitation, $token] = $invitations->issue($email, (string) $this->argument('name'), [$role], null, send: (bool) $this->option('send'));
        Audit::record('admin.bootstrap_invitation', "Console issued {$role->label()} invitation for {$email}", $invitation);

        if ($this->option('send')) {
            $this->info("Invitation emailed to {$email}. It expires {$invitation->expires_at->toDayDateTimeString()} UTC.");
        } else {
            $this->info('One-time link (shown once, not stored). Open it to set a password:');
            $this->line(route('invitation.show', $token));
            $this->comment("Expires {$invitation->expires_at->toDayDateTimeString()} UTC.");
        }

        return self::SUCCESS;
    }
}
