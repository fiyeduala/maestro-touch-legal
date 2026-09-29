<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/** Prints a hash for STAGING_PASSWORD_HASH. The password is typed hidden and is never stored or logged. */
class StagingPassword extends Command
{
    protected $signature = 'mtl:staging-password';

    protected $description = 'Create the STAGING_PASSWORD_HASH value for the staging site password';

    public function handle(): int
    {
        $password = (string) $this->secret('Staging password (at least 12 characters)');
        if (mb_strlen($password) < 12) {
            $this->error('Use at least 12 characters.');

            return self::FAILURE;
        }
        if ($password !== (string) $this->secret('Type it again')) {
            $this->error('The two passwords do not match.');

            return self::FAILURE;
        }

        // Single quotes keep the "$" characters in the hash literal inside .env.
        $this->line("STAGING_PASSWORD_HASH='".Hash::make($password)."'");
        $this->info('Put that line in the staging .env, with STAGING_USER=<a username>. Share the password in person, not by email.');

        return self::SUCCESS;
    }
}
