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

    public function test_rolling_back_the_last_migration_reverses_it_and_remigrating_restores_the_schema(): void
    {
        // Asserts the round trip, not that the last migration is
        // structural. It deliberately doesn't hardcode which table is
        // touched (that changes whenever a migration is added), doesn't
        // assume a whole table is created rather than a column altered
        // (e.g. add_is_active_to_plans_table), and doesn't assume the last
        // migration changes the schema at all — a data-only repair
        // migration such as normalize_absolute_screenshot_paths correctly
        // has an empty down() and leaves the schema untouched. What must
        // always hold is that rollback + re-migrate lands back on exactly
        // the same tables and columns.
        Artisan::call('migrate:fresh', ['--force' => true]);
        $tablesBefore = Schema::getTableListing();
        $columnsBefore = $this->columnFingerprint($tablesBefore);

        $rollbackExitCode = Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
        $this->assertSame(0, $rollbackExitCode);

        $migrateExitCode = Artisan::call('migrate', ['--force' => true]);
        $this->assertSame(0, $migrateExitCode);

        $tablesAfterRemigrate = Schema::getTableListing();
        sort($tablesBefore);
        sort($tablesAfterRemigrate);
        $this->assertSame($tablesBefore, $tablesAfterRemigrate, 'Re-running migrate should restore the exact same set of tables');
        $this->assertSame($columnsBefore, $this->columnFingerprint($tablesAfterRemigrate), 'Re-running migrate should restore the exact same columns');
    }

    /**
     * @param  string[]  $tables
     * @return array<string, string[]>
     */
    private function columnFingerprint(array $tables): array
    {
        $fingerprint = [];
        foreach ($tables as $table) {
            $columns = Schema::getColumnListing($table);
            sort($columns);
            $fingerprint[$table] = $columns;
        }
        ksort($fingerprint);

        return $fingerprint;
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
            'reports', 'plans', 'subscriptions', 'usage_logs',
            'leads', 'lead_activities', 'cms_pages', 'cms_sections',
            'audit_schedules', 'api_providers', 'api_usage_logs', 'personal_access_tokens',
            'features', 'plan_features', 'ai_models', 'plan_ai_models',
            'screenshots', 'issue_pages',
        ];
    }
}
