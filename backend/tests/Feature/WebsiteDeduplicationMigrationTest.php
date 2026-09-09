<?php

namespace Tests\Feature;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The websites migration is the one piece of this change that *deletes*
 * rows from a live database, and the only piece the rest of the suite
 * never exercises: every other test starts from a fresh schema with no
 * pre-existing duplicates, so the merge path runs zero times.
 *
 * Each test here rolls the migration back, recreates the pre-migration
 * state (one `websites` row per audit submission, the old `url` column),
 * and runs it forward again — which is exactly what will happen to the
 * real database.
 */
class WebsiteDeduplicationMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path(
            'migrations/2026_08_31_000000_add_profile_and_normalized_domain_to_websites_table.php'
        );
    }

    /**
     * Undo the migration, then insert rows the way the old code did.
     *
     * @param  array<int, array{user_id: int|null, url: string, domain: string}>  $websites
     * @return array<int, int> inserted website ids, in the order given
     */
    private function seedPreMigrationWebsites(array $websites): array
    {
        $this->migration()->down();

        $ids = [];

        foreach ($websites as $website) {
            $ids[] = DB::table('websites')->insertGetId([
                'user_id' => $website['user_id'],
                'url' => $website['url'],
                'domain' => $website['domain'],
                'platform' => 'unknown',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $ids;
    }

    private function makeUserId(string $email = 'ada@example.com'): int
    {
        return DB::table('users')->insertGetId([
            'name' => 'Ada',
            'email' => $email,
            'password' => 'x',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeAudit(int $websiteId, ?int $userId = null): int
    {
        return DB::table('audits')->insertGetId([
            'website_id' => $websiteId,
            'user_id' => $userId,
            'status' => 'completed',
            'max_pages' => 25,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_column_is_renamed_and_the_new_fields_exist(): void
    {
        $this->seedPreMigrationWebsites([]);

        $this->assertTrue(Schema::hasColumn('websites', 'url'));
        $this->assertFalse(Schema::hasColumn('websites', 'normalized_domain'));

        $this->migration()->up();

        $this->assertFalse(Schema::hasColumn('websites', 'url'));
        foreach ([
            'primary_url', 'normalized_domain', 'name', 'category', 'description',
            'products_or_services', 'country', 'budget', 'average_product_cost', 'competitors',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('websites', $column), "Missing column: {$column}");
        }
    }

    public function test_existing_rows_are_backfilled_with_a_normalized_domain_and_a_name(): void
    {
        $userId = $this->makeUserId();
        $this->seedPreMigrationWebsites([
            ['user_id' => $userId, 'url' => 'https://WWW.Example.com/', 'domain' => 'WWW.Example.com'],
        ]);

        $this->migration()->up();

        $website = DB::table('websites')->first();
        $this->assertSame('example.com', $website->normalized_domain);
        // A row with no name shows its domain, decided once here rather
        // than by every listing that reads it later.
        $this->assertSame('example.com', $website->name);
        $this->assertSame('https://WWW.Example.com/', $website->primary_url);
        // `domain` is untouched — the crawler compares against it and needs
        // it exactly as the site served it.
        $this->assertSame('WWW.Example.com', $website->domain);
    }

    public function test_duplicate_rows_for_one_user_are_merged_onto_the_earliest(): void
    {
        $userId = $this->makeUserId();

        [$first, $second, $third] = $this->seedPreMigrationWebsites([
            ['user_id' => $userId, 'url' => 'https://example.com/', 'domain' => 'example.com'],
            ['user_id' => $userId, 'url' => 'https://example.com/pricing', 'domain' => 'example.com'],
            ['user_id' => $userId, 'url' => 'https://www.example.com/', 'domain' => 'www.example.com'],
        ]);

        $auditA = $this->makeAudit($first, $userId);
        $auditB = $this->makeAudit($second, $userId);
        $auditC = $this->makeAudit($third, $userId);

        $this->migration()->up();

        $this->assertSame(1, DB::table('websites')->count());
        $this->assertSame($first, DB::table('websites')->first()->id);

        // The audits are history, not collateral — every one is repointed.
        foreach ([$auditA, $auditB, $auditC] as $auditId) {
            $this->assertSame(
                $first,
                DB::table('audits')->where('id', $auditId)->value('website_id'),
            );
        }
    }

    public function test_two_users_with_the_same_domain_are_not_merged_together(): void
    {
        $ada = $this->makeUserId('ada@example.com');
        $grace = $this->makeUserId('grace@example.com');

        $this->seedPreMigrationWebsites([
            ['user_id' => $ada, 'url' => 'https://example.com/', 'domain' => 'example.com'],
            ['user_id' => $ada, 'url' => 'https://example.com/pricing', 'domain' => 'example.com'],
            ['user_id' => $grace, 'url' => 'https://example.com/', 'domain' => 'example.com'],
        ]);

        $this->migration()->up();

        // Ada's two collapse to one; Grace's stays hers.
        $this->assertSame(2, DB::table('websites')->count());
        $this->assertSame(1, DB::table('websites')->where('user_id', $ada)->count());
        $this->assertSame(1, DB::table('websites')->where('user_id', $grace)->count());
    }

    public function test_anonymous_rows_are_left_alone(): void
    {
        $this->seedPreMigrationWebsites([
            ['user_id' => null, 'url' => 'https://example.com/', 'domain' => 'example.com'],
            ['user_id' => null, 'url' => 'https://example.com/', 'domain' => 'example.com'],
        ]);

        $this->migration()->up();

        // Merging them would retroactively hand every anonymous audit of
        // this domain to whoever holds the surviving row's id.
        $this->assertSame(2, DB::table('websites')->whereNull('user_id')->count());
    }

    public function test_usage_logs_are_repointed_at_the_surviving_website(): void
    {
        $userId = $this->makeUserId();

        [$first, $second] = $this->seedPreMigrationWebsites([
            ['user_id' => $userId, 'url' => 'https://example.com/', 'domain' => 'example.com'],
            ['user_id' => $userId, 'url' => 'https://example.com/', 'domain' => 'example.com'],
        ]);

        DB::table('usage_logs')->insert([
            'user_id' => $userId,
            'ip_address' => '203.0.113.5',
            'action' => 'audit_created',
            'website_id' => $second,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration()->up();

        $this->assertSame($first, DB::table('usage_logs')->first()->website_id);
    }

    public function test_a_duplicates_schedule_is_moved_and_the_surplus_dropped(): void
    {
        $userId = $this->makeUserId();

        [$first, $second] = $this->seedPreMigrationWebsites([
            ['user_id' => $userId, 'url' => 'https://example.com/', 'domain' => 'example.com'],
            ['user_id' => $userId, 'url' => 'https://example.com/', 'domain' => 'example.com'],
        ]);

        DB::table('audit_schedules')->insert([
            'user_id' => $userId,
            'website_id' => $second,
            'frequency' => 'weekly',
            'is_active' => true,
            'next_run_at' => now()->addWeek(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration()->up();

        // audit_schedules has its own unique (user_id, website_id), so the
        // schedule has to move rather than be duplicated onto the survivor.
        $schedules = DB::table('audit_schedules')->get();
        $this->assertCount(1, $schedules);
        $this->assertSame($first, $schedules[0]->website_id);
    }

    public function test_a_schedule_on_the_survivor_wins_and_the_duplicates_is_dropped(): void
    {
        $userId = $this->makeUserId();

        [$first, $second] = $this->seedPreMigrationWebsites([
            ['user_id' => $userId, 'url' => 'https://example.com/', 'domain' => 'example.com'],
            ['user_id' => $userId, 'url' => 'https://example.com/', 'domain' => 'example.com'],
        ]);

        foreach ([$first => 'daily', $second => 'monthly'] as $websiteId => $frequency) {
            DB::table('audit_schedules')->insert([
                'user_id' => $userId,
                'website_id' => $websiteId,
                'frequency' => $frequency,
                'is_active' => true,
                'next_run_at' => now()->addDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->migration()->up();

        // Moving the duplicate's schedule would collide with the
        // survivor's own; one website has one schedule by definition.
        $schedules = DB::table('audit_schedules')->get();
        $this->assertCount(1, $schedules);
        $this->assertSame($first, $schedules[0]->website_id);
        $this->assertSame('daily', $schedules[0]->frequency);
    }

    public function test_the_unique_index_is_in_place_after_the_merge(): void
    {
        $userId = $this->makeUserId();

        $this->seedPreMigrationWebsites([
            ['user_id' => $userId, 'url' => 'https://example.com/', 'domain' => 'example.com'],
            ['user_id' => $userId, 'url' => 'https://example.com/', 'domain' => 'example.com'],
        ]);

        $this->migration()->up();

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('websites')->insert([
            'user_id' => $userId,
            'primary_url' => 'https://example.com/',
            'domain' => 'example.com',
            'normalized_domain' => 'example.com',
            'platform' => 'unknown',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
