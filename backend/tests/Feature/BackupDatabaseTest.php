<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The database backup command (§56).
 *
 * Added after a development database was recreated by a `migrate:fresh`
 * with no dump in existence. What is tested here is the refusal path,
 * because the dangerous failure for a backup tool is not "it errored" —
 * it is "it wrote something that looks like a backup and isn't".
 */
class BackupDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_a_connection_it_cannot_actually_dump(): void
    {
        $this->useNonMysqlConnection();

        $this->artisan('db:backup')
            ->expectsOutputToContain('only supports MySQL')
            ->assertExitCode(1);
    }

    public function test_it_does_not_leave_a_file_behind_when_it_refuses(): void
    {
        $this->useNonMysqlConnection();

        $before = glob(storage_path('backups').'/*.sql') ?: [];

        $this->artisan('db:backup')->assertExitCode(1);

        $after = glob(storage_path('backups').'/*.sql') ?: [];

        // An empty or partial dump restores cleanly and silently loses
        // everything after the point it stopped, which is worse than
        // having no backup at all — at least then you know.
        $this->assertSame(count($before), count($after));
    }

    /**
     * Point the default connection at something that is not MySQL.
     *
     * In-memory rather than the on-disk sqlite default: RefreshDatabase
     * still has a transaction open on teardown, and pointing it at a file
     * that does not exist throws before the assertion ever runs.
     */
    private function useNonMysqlConnection(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
    }

    public function test_dumps_are_git_ignored(): void
    {
        $gitignore = file_get_contents(base_path('.gitignore'));

        // A dump carries hashed passwords, encrypted provider keys and
        // customer leads. Committing one is a data breach, not an untidy
        // repository.
        $this->assertStringContainsString('/storage/backups', $gitignore);
    }
}
