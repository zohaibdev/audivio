<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Dump the application database to a file (§56).
 *
 * Added after a development database was recreated by a `migrate:fresh`
 * and 26 audits went with it, because no dump existed. The lost rows were
 * only development data; the standing risk was that nothing would have
 * caught it happening again either.
 *
 * Deliberately plain: a `mysqldump` to a timestamped file under
 * `storage/backups`, with old files pruned. No scheduling, no remote
 * upload, no encryption — those are deployment decisions, and a backup
 * command that pretends to more than it does is worse than none.
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--keep=10 : How many dumps to retain}';

    protected $description = 'Write a timestamped SQL dump of the configured database to storage/backups';

    public function handle(): int
    {
        $connection = config('database.default');

        if ($connection !== 'mysql') {
            // Refusing beats writing an empty file that looks like a
            // backup until the day someone needs it.
            $this->error("db:backup only supports MySQL; the configured connection is '{$connection}'.");

            return self::FAILURE;
        }

        $config = config("database.connections.{$connection}");
        $binary = $this->locateMysqldump();

        if ($binary === null) {
            $this->error('Could not find mysqldump. Add its directory to PATH, or set DB_DUMP_BINARY in .env.');

            return self::FAILURE;
        }

        $directory = storage_path('backups');
        File::ensureDirectoryExists($directory);

        $target = $directory.DIRECTORY_SEPARATOR.sprintf(
            '%s-%s.sql',
            $config['database'],
            now()->format('Y-m-d-His'),
        );

        // Array form, so nothing in a database name is interpreted by a
        // shell.
        //
        // The password goes through the environment, never `--password=`
        // on the argument list: command lines are readable by any other
        // process on the machine (`ps -ef`, `Win32_Process.CommandLine`),
        // so passing it as an argument publishes the database password to
        // every local user for the life of the dump. mysqldump warns about
        // this itself.
        $process = new Process([
            $binary,
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--user='.$config['username'],
            '--single-transaction',
            '--routines',
            '--events',
            // Without this, mysqldump 5.7 negotiates `utf8` — three bytes —
            // and silently replaces every 4-byte character (emoji, some
            // CJK) with `?`. A backup that restores cleanly having quietly
            // mangled rows is the exact failure this command's own error
            // handling is written to avoid.
            '--default-character-set=utf8mb4',
            $config['database'],
        ], timeout: 600, env: ['MYSQL_PWD' => (string) $config['password']]);

        $handle = fopen($target, 'w');

        if ($handle === false) {
            // Otherwise the write callback below calls fwrite(false, ...),
            // which is a TypeError in PHP 8 — an unwritable backups
            // directory would surface as a crash rather than a message.
            $this->error("Could not open {$target} for writing.");

            return self::FAILURE;
        }

        $process->run(function (string $type, string $buffer) use ($handle) {
            if ($type === Process::OUT) {
                fwrite($handle, $buffer);
            }
        });

        fclose($handle);

        if (! $process->isSuccessful()) {
            // A partial dump is more dangerous than none: it restores
            // cleanly and silently loses whatever came after the failure.
            @unlink($target);

            $this->error('mysqldump failed: '.trim($process->getErrorOutput()));

            return self::FAILURE;
        }

        // The stat cache can still hold the pre-write entry for this path.
        clearstatcache(true, $target);

        $bytes = filesize($target) ?: 0;

        if ($bytes === 0) {
            @unlink($target);
            $this->error('mysqldump produced an empty file; nothing was written.');

            return self::FAILURE;
        }

        $this->info(sprintf('Wrote %s (%s KB).', $target, number_format($bytes / 1024, 1)));

        $this->prune($directory, (int) $this->option('keep'));

        return self::SUCCESS;
    }

    /**
     * Keep the newest $keep dumps and delete the rest.
     */
    private function prune(string $directory, int $keep): void
    {
        if ($keep < 1) {
            return;
        }

        $dumps = collect(File::files($directory))
            ->filter(fn ($file) => $file->getExtension() === 'sql')
            ->sortByDesc(fn ($file) => $file->getMTime())
            ->values();

        foreach ($dumps->slice($keep) as $old) {
            File::delete($old->getPathname());
            $this->line('Pruned '.$old->getFilename());
        }
    }

    /**
     * mysqldump is not reliably on PATH on a WAMP/XAMPP box, so the
     * configured PHP binary's neighbourhood is checked too.
     */
    private function locateMysqldump(): ?string
    {
        $configured = env('DB_DUMP_BINARY');

        if (is_string($configured) && $configured !== '' && is_executable($configured)) {
            return $configured;
        }

        $candidates = array_merge(
            glob('E:/wamp/bin/mysql/*/bin/mysqldump.exe') ?: [],
            glob('C:/wamp64/bin/mysql/*/bin/mysqldump.exe') ?: [],
            glob('C:/xampp/mysql/bin/mysqldump.exe') ?: [],
            ['/usr/bin/mysqldump', '/usr/local/bin/mysqldump'],
        );

        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        // Last resort: let the OS resolve it from PATH.
        $which = new Process(PHP_OS_FAMILY === 'Windows' ? ['where', 'mysqldump'] : ['which', 'mysqldump']);
        $which->run();

        if ($which->isSuccessful()) {
            $found = trim(strtok($which->getOutput(), "\n") ?: '');

            return $found !== '' ? $found : null;
        }

        return null;
    }
}
