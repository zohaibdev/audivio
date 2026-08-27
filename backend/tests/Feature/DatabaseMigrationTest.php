<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verifies migration down() methods actually work, not just up(). Does
 * NOT use RefreshDatabase — it manages migration state itself and
 * finishes fully migrated again, so it doesn't leave the shared
 * in-memory SQLite connection in a state other tests don't expect.
 */
class DatabaseMigrationTest extends TestCase
{
    public function test_migrate_fresh_runs_cleanly(): void
    {
        $exitCode = Artisan::call('migrate:fresh', ['--force' => true]);

        $this->assertSame(0, $exitCode);

        foreach ($this->allApplicationTables() as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected table '{$table}' to exist after migrate:fresh");
        }
    }

    public function test_rolling_back_the_last_migration_drops_its_table_and_remigrating_restores_it(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->assertTrue(Schema::hasTable('ai_analyses'));

        $rollbackExitCode = Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
        $this->assertSame(0, $rollbackExitCode);
        $this->assertFalse(Schema::hasTable('ai_analyses'), 'ai_analyses table should be dropped after rolling back its migration');

        $migrateExitCode = Artisan::call('migrate', ['--force' => true]);
        $this->assertSame(0, $migrateExitCode);
        $this->assertTrue(Schema::hasTable('ai_analyses'), 'ai_analyses table should be restored after re-running migrate');
    }

    public function test_rolling_back_every_migration_drops_every_application_table(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);

        $exitCode = Artisan::call('migrate:reset', ['--force' => true]);
        $this->assertSame(0, $exitCode);

        foreach ($this->allApplicationTables() as $table) {
            $this->assertFalse(Schema::hasTable($table), "Expected table '{$table}' to be gone after migrate:reset");
        }

        // Leave the schema fully migrated again for whichever test runs next.
        Artisan::call('migrate', ['--force' => true]);
    }

    /**
     * @return string[]
     */
    private function allApplicationTables(): array
    {
        return [
            'users', 'websites', 'audits', 'crawl_jobs', 'crawl_urls',
            'audit_pages', 'audit_issues', 'audit_scores', 'ai_analyses',
            'personal_access_tokens',
        ];
    }
}
