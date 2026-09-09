<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Create or promote an admin account (§59).
 *
 * There is deliberately no "promote to admin" API endpoint (§9) — an
 * HTTP route that grants admin is a route that can be found. But that
 * left the first admin to a hand-written database row, which is easy to
 * get wrong and had to be redone from memory every time the development
 * database was rebuilt.
 *
 * Promotion is separate from creation on purpose: running this against an
 * existing account grants admin and leaves the password alone, so it can
 * never silently reset someone's credentials.
 */
class MakeAdmin extends Command
{
    protected $signature = 'make:admin
        {email : The account to create or promote}
        {--name= : Display name, for a new account}
        {--ask-password : Prompt for the password without echoing it}
        {--password= : Set non-interactively. Prefer --ask-password: an argument is visible in shell history and the process list}';

    protected $description = 'Create an admin account, or grant admin to an existing user';

    /**
     * The password the operator asked for, or null to generate one.
     *
     * `--ask-password` reads it without echoing and never puts it in shell
     * history or the process list — the same exposure that made the
     * database password move off mysqldump's argument list (§59). The
     * `--password` flag stays for scripted use and says so, with a warning
     * at the point of use rather than only in the help text.
     */
    private function requestedPassword(): ?string
    {
        if ($this->option('ask-password')) {
            $entered = (string) $this->secret('New password (input hidden)');

            if ($entered !== (string) $this->secret('Confirm password')) {
                $this->error('Passwords did not match.');

                exit(self::FAILURE);
            }

            return $entered;
        }

        $given = $this->option('password');

        if ($given === null || $given === '') {
            return null;
        }

        $this->warn('--password was passed as an argument, so it is now in your shell history and was visible in the process list. Prefer --ask-password.');

        return (string) $given;
    }

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("'{$email}' is not a valid email address.");

            return self::FAILURE;
        }

        $requested = $this->requestedPassword();

        $existing = User::where('email', $email)->first();

        if ($existing !== null) {
            if ($existing->is_admin && $requested === null) {
                $this->info("{$email} is already an admin. Nothing to do.");

                return self::SUCCESS;
            }

            $changes = ['is_admin' => true];

            // Only when explicitly asked. Promoting an account must never
            // silently reset its owner's password — but there is no
            // self-service password change in the app yet (§29), so this
            // is the only way to rotate one.
            if ($requested !== null) {
                $changes['password'] = $requested;
            }

            $existing->update($changes);

            $this->info($requested !== null
                ? "Granted admin to {$email} and set a new password."
                : "Granted admin to the existing account {$email}. Their password is unchanged.");

            return self::SUCCESS;
        }

        $password = $requested ?? Str::password(16);

        if (strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        // `password` is cast to `hashed` on the model, so this is stored
        // as a bcrypt hash and never in the clear.
        $user = User::create([
            'name' => (string) ($this->option('name') ?: Str::before($email, '@')),
            'email' => $email,
            'password' => $password,
            'is_admin' => true,
        ]);

        $this->info("Created admin account #{$user->id} ({$email}).");

        if ($requested === null) {
            $this->newLine();
            // Escaped: Symfony Console parses `<...>` as formatting tags,
            // so a generated password containing `<` was printed with a
            // chunk silently missing — displaying a password that would
            // not log anyone in (§59).
            $this->line('  Password: '.OutputFormatter::escape($password));
            $this->newLine();
            // Shown once because it was generated here and stored only as
            // a hash — nothing can recover it later.
            // Honest about the route: there is no self-service password
            // change in the app yet (§29), so re-running this command is
            // how you rotate it.
            $this->comment('Shown once — it is stored only as a hash and cannot be recovered.');
            // Points at the flag that does not leak. Recommending
            // `--password` here would have the command advise the exact
            // exposure it warns about two branches away (§59).
            $this->comment('To change it: php artisan make:admin '.$email.' --ask-password');
        }

        return self::SUCCESS;
    }
}
