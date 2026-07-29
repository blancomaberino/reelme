<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Promote an existing user to admin. This is the production-safe way to grant
 * admin (never the dev seeder). Usage: `php artisan app:make-admin user@example.com`.
 */
class MakeAdmin extends Command
{
    protected $signature = 'app:make-admin {email : Email of the user to promote}';

    protected $description = 'Grant the admin role to an existing user by email';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        // Look past the SoftDeletes scope so a banned (soft-deleted) user is
        // detected and refused explicitly, rather than silently reading as
        // "No user found" — promoting a banned account to admin (or quietly
        // restoring it) is never what an operator intends. (T-058)
        $user = User::withTrashed()->where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with email [{$email}].");

            return self::FAILURE;
        }

        if ($user->trashed()) {
            $this->error("[{$email}] is banned (soft-deleted); restore the user before promoting.");

            return self::FAILURE;
        }

        if ($user->is_admin) {
            $this->info("[{$email}] is already an admin.");

            return self::SUCCESS;
        }

        $user->forceFill(['is_admin' => true])->save();
        $this->info("[{$email}] is now an admin.");

        return self::SUCCESS;
    }
}
