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

    public function test_anonymous_audit_has_no_full_access(): void
    {
        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'status' => 'completed', 'max_pages' => 5]);

        $this->assertFalse((new ReportAccessResolver)->hasFullAccess($audit));
    }

    public function test_registered_users_audit_has_full_access(): void
    {
        $user = User::create(['name' => 'Test', 'email' => 'test@example.com', 'password' => 'x']);
        $website = Website::create(['user_id' => $user->id, 'url' => 'https://example.com', 'domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'user_id' => $user->id, 'status' => 'completed', 'max_pages' => 5]);

        $this->assertTrue((new ReportAccessResolver)->hasFullAccess($audit));
    }
}
