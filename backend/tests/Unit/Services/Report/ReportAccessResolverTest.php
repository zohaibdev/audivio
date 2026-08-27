<?php

namespace Tests\Unit\Services\Report;

use App\Models\Audit;
use App\Models\User;
use App\Models\Website;
use App\Services\Report\ReportAccessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportAccessResolverTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email = 'test@example.com'): User
    {
        return User::create(['name' => 'Test', 'email' => $email, 'password' => 'x']);
    }

    public function test_anonymous_audit_grants_no_one_full_access_even_a_logged_in_viewer(): void
    {
        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'status' => 'completed', 'max_pages' => 5]);
        $viewer = $this->makeUser();

        $this->assertFalse((new ReportAccessResolver)->hasFullAccess($audit, null));
        $this->assertFalse((new ReportAccessResolver)->hasFullAccess($audit, $viewer));
    }

    public function test_owner_viewing_their_own_audit_has_full_access(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $website = Website::create(['user_id' => $owner->id, 'url' => 'https://example.com', 'domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'user_id' => $owner->id, 'status' => 'completed', 'max_pages' => 25]);

        $this->assertTrue((new ReportAccessResolver)->hasFullAccess($audit, $owner));
    }

    public function test_a_different_registered_user_does_not_get_full_access_to_someone_elses_audit(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $someoneElse = $this->makeUser('someone-else@example.com');
        $website = Website::create(['user_id' => $owner->id, 'url' => 'https://example.com', 'domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'user_id' => $owner->id, 'status' => 'completed', 'max_pages' => 25]);

        $this->assertFalse((new ReportAccessResolver)->hasFullAccess($audit, $someoneElse));
    }

    public function test_anonymous_viewer_of_a_registered_users_audit_has_no_full_access(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $website = Website::create(['user_id' => $owner->id, 'url' => 'https://example.com', 'domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'user_id' => $owner->id, 'status' => 'completed', 'max_pages' => 25]);

        $this->assertFalse((new ReportAccessResolver)->hasFullAccess($audit, null));
    }
}
