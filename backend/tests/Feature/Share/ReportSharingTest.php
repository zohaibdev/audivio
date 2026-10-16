<?php

namespace Tests\Feature\Share;

use App\Enums\ScreenshotViewport;
use App\Enums\ShareEvent;
use App\Models\Audit;
use App\Models\AuditPage;
use App\Models\AuditScore;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportShare;
use App\Models\ReportShareEvent;
use App\Models\Screenshot;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;
use App\Services\Report\ScreenshotUrlSigner;
use App\Services\Share\ShareTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Password-protected report sharing (§50).
 *
 * Almost every test here is an attack. The feature hands a URL to someone
 * with no account, so the interesting question is never "does it work"
 * but "what else does it let them do".
 */
class ReportSharingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Audit $audit;

    private const PASSWORD = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('crawler.screenshot_disk'));

        $this->owner = $this->makeUser('owner@example.com', ['report_sharing' => true, 'pdf_export' => true]);
        $this->audit = $this->makeAudit($this->owner);
    }

    private function makeUser(string $email, array $features = []): User
    {
        $user = User::create(['name' => 'User', 'email' => $email, 'password' => 'x']);

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => Plan::create([
                'name' => 'Plan '.$email, 'slug' => 'plan-'.md5($email), 'price_cents' => 0,
                'max_pages' => 25, 'daily_audit_limit' => 10,
                'report_visibility_percent' => 100, 'issues_per_service' => null,
                'features' => $features,
            ])->id,
            'started_at' => now(),
        ]);

        return $user->fresh();
    }

    private function makeAudit(User $owner): Audit
    {
        $website = Website::create([
            'user_id' => $owner->id, 'primary_url' => 'https://example.com/',
            'domain' => 'example.com', 'normalized_domain' => 'example.com', 'platform' => 'unknown',
        ]);

        $audit = Audit::create([
            'website_id' => $website->id, 'user_id' => $owner->id, 'status' => 'completed',
            'max_pages' => 5, 'pages_crawled' => 1, 'completed_at' => now(),
        ]);

        AuditScore::create(['audit_id' => $audit->id, 'category' => 'seo', 'score' => 70]);
        AuditScore::create(['audit_id' => $audit->id, 'category' => 'overall', 'score' => 70]);

        $page = AuditPage::create([
            'audit_id' => $audit->id, 'url' => 'https://example.com/',
            'http_status' => 200, 'title' => 'Home',
        ]);

        Storage::disk(config('crawler.screenshot_disk'))->put('screenshots/s.png', 'png');
        Screenshot::create([
            'audit_id' => $audit->id, 'audit_page_id' => $page->id,
            'viewport' => ScreenshotViewport::Desktop, 'width' => 1366, 'height' => 900,
            'storage_path' => 'screenshots/s.png',
        ]);

        Report::create([
            'audit_id' => $audit->id,
            'content' => ['overview' => [], 'issues' => [], 'recommendations' => [], 'ai_analysis' => [], 'pages' => []],
            'generated_at' => now(),
        ]);

        return $audit;
    }

    private function token(User $user): string
    {
        return $user->createToken('api')->plainTextToken;
    }

    private function asOwner(?User $user = null): self
    {
        // Sanctum memoizes the resolved user per guard instance, so a
        // simulated request that ran unauthenticated leaves the previous
        // one in place (CONTEXT.md §28). Without this, a test that
        // unlocks a share and *then* checks an owner route silently runs
        // the second request as the owner.
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$this->token($user ?? $this->owner));
    }

    /**
     * Explicitly nobody — the state a share recipient is actually in.
     */
    private function asRecipient(): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => '']);
    }

    /**
     * @return array{token: string, share: ReportShare}
     */
    private function createShare(array $payload = []): array
    {
        $response = $this->asOwner()->postJson("/api/audits/{$this->audit->id}/shares", array_merge([
            'password' => self::PASSWORD,
        ], $payload))->assertStatus(201);

        $url = $response->json('data.url');
        $token = substr($url, strrpos($url, '/') + 1);

        return ['token' => $token, 'share' => ReportShare::latest('id')->first()];
    }

    private function unlock(string $token, string $password = self::PASSWORD): ?string
    {
        return $this->asRecipient()
            ->postJson("/api/shared-reports/{$token}/unlock", ['password' => $password])
            ->json('data.access_token');
    }

    // --- Creating a share ---

    public function test_the_owner_can_create_a_share_and_the_url_carries_no_audit_id(): void
    {
        ['token' => $token] = $this->createShare();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);

        // The whole point of a capability URL: no path segment invites
        // you to change a number and see someone else's report. Asserted
        // on the URL's shape, not on the hex — a 64-character hex string
        // contains almost every digit by chance.
        $url = ReportShare::latest('id')->first() ? app(ShareTokenService::class)->urlFor($token) : '';
        $this->assertStringEndsWith("/shared-report/{$token}", $url);
        $this->assertStringNotContainsString('/audits/', $url);
    }

    public function test_the_token_is_stored_hashed_never_in_plaintext(): void
    {
        ['token' => $token, 'share' => $share] = $this->createShare();

        // A read of this table must not hand over working links.
        $this->assertSame(hash('sha256', $token), $share->token_hash);
        $this->assertNotSame($token, $share->token_hash);
        $this->assertDatabaseMissing('report_shares', ['token_hash' => $token]);
    }

    public function test_the_password_is_stored_hashed(): void
    {
        ['share' => $share] = $this->createShare();

        $this->assertNotSame(self::PASSWORD, $share->password_hash);
        $this->assertTrue(password_verify(self::PASSWORD, $share->password_hash));
    }

    public function test_a_share_cannot_be_created_without_a_password(): void
    {
        // A share with no password is a public URL, which is a different
        // feature with different consequences.
        $this->asOwner()
            ->postJson("/api/audits/{$this->audit->id}/shares", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_the_url_is_returned_once_and_never_again(): void
    {
        $this->createShare();

        $listed = $this->asOwner()->getJson("/api/audits/{$this->audit->id}/shares")->json('data.shares.0');

        // Nothing in the owner's own listing can reconstruct the link —
        // a system that can re-display a token can be made to.
        $this->assertArrayNotHasKey('url', $listed);
        $this->assertArrayNotHasKey('token', $listed);
        $this->assertArrayNotHasKey('token_hash', $listed);
        $this->assertArrayNotHasKey('password_hash', $listed);
    }

    // --- Unauthorized share creation ---

    public function test_a_stranger_cannot_create_a_share_on_someone_elses_audit(): void
    {
        $stranger = $this->makeUser('stranger@example.com', ['report_sharing' => true]);

        // 404, not 403: telling them the audit exists is already telling
        // them something about another account.
        $this->asOwner($stranger)
            ->postJson("/api/audits/{$this->audit->id}/shares", ['password' => self::PASSWORD])
            ->assertStatus(404);

        $this->assertSame(0, ReportShare::count());
    }

    public function test_an_anonymous_caller_cannot_create_a_share(): void
    {
        $this->postJson("/api/audits/{$this->audit->id}/shares", ['password' => self::PASSWORD])
            ->assertStatus(401);
    }

    public function test_a_plan_without_sharing_cannot_create_one(): void
    {
        $limited = $this->makeUser('limited@example.com', ['report_sharing' => false]);
        $audit = $this->makeAudit($limited);

        $this->asOwner($limited)
            ->postJson("/api/audits/{$audit->id}/shares", ['password' => self::PASSWORD])
            ->assertStatus(403);
    }

    public function test_a_lapsed_subscription_loses_sharing(): void
    {
        Subscription::where('user_id', $this->owner->id)->update(['ends_at' => now()->subDay()]);

        // Entitlements are resolved per request, so the feature goes as
        // soon as the plan does (§40).
        $this->asOwner()
            ->postJson("/api/audits/{$this->audit->id}/shares", ['password' => self::PASSWORD])
            ->assertStatus(403);
    }

    // --- The password gate ---

    public function test_a_valid_password_unlocks_the_report(): void
    {
        ['token' => $token] = $this->createShare();

        $access = $this->postJson("/api/shared-reports/{$token}/unlock", ['password' => self::PASSWORD])
            ->assertStatus(200)
            ->json('data.access_token');

        $this->assertNotEmpty($access);

        $this->asRecipient()->withHeader('X-Share-Access', $access)
            ->getJson("/api/shared-reports/{$token}/report")
            ->assertStatus(200)
            ->assertJsonPath('data.is_shared_view', true);
    }

    public function test_an_invalid_password_is_refused_and_yields_no_access(): void
    {
        ['token' => $token] = $this->createShare();

        $this->postJson("/api/shared-reports/{$token}/unlock", ['password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonMissingPath('data.access_token');
    }

    public function test_nothing_about_the_report_is_returned_before_the_password(): void
    {
        ['token' => $token] = $this->createShare();

        $body = $this->getJson("/api/shared-reports/{$token}")->assertStatus(200)->json('data');

        // The lock screen needs to know a password is wanted and whose
        // branding to wear. It does not need the audited domain — that
        // alone would tell a stranger who the owner's client is.
        $this->assertTrue($body['requires_password']);
        $encoded = json_encode($body);
        $this->assertStringNotContainsString('example.com', $encoded);
        $this->assertStringNotContainsString('score', $encoded);
    }

    public function test_the_report_cannot_be_read_without_unlocking(): void
    {
        ['token' => $token] = $this->createShare();

        // Knowing the URL is not knowing the password.
        $this->getJson("/api/shared-reports/{$token}/report")->assertStatus(401);
        $this->getJson("/api/shared-reports/{$token}/pages")->assertStatus(401);
        $this->getJson("/api/shared-reports/{$token}/pdf/seo")->assertStatus(401);
    }

    // --- Repeated failures ---

    public function test_repeated_invalid_passwords_lock_the_link(): void
    {
        ['token' => $token] = $this->createShare();
        $max = (int) config('report_sharing.lockout.max_attempts');

        for ($i = 0; $i < $max; $i++) {
            $this->postJson("/api/shared-reports/{$token}/unlock", ['password' => 'wrong'])
                ->assertStatus(422);
        }

        // The next attempt is refused before the password is even checked
        // — including a *correct* one, which is the point.
        $this->postJson("/api/shared-reports/{$token}/unlock", ['password' => self::PASSWORD])
            ->assertStatus(429);
    }

    public function test_a_successful_unlock_clears_earlier_failures(): void
    {
        ['token' => $token] = $this->createShare();

        $this->postJson("/api/shared-reports/{$token}/unlock", ['password' => 'wrong'])->assertStatus(422);
        $this->postJson("/api/shared-reports/{$token}/unlock", ['password' => 'wrong'])->assertStatus(422);
        $this->postJson("/api/shared-reports/{$token}/unlock", ['password' => self::PASSWORD])->assertStatus(200);

        // Someone who mistyped twice must not carry those toward a
        // lockout for the rest of the window.
        for ($i = 0; $i < 3; $i++) {
            $this->postJson("/api/shared-reports/{$token}/unlock", ['password' => 'wrong'])->assertStatus(422);
        }
    }

    // --- Revocation and expiry ---

    public function test_a_revoked_link_stops_working(): void
    {
        ['token' => $token, 'share' => $share] = $this->createShare();
        $access = $this->unlock($token);

        $this->asOwner()->deleteJson("/api/audits/{$this->audit->id}/shares/{$share->id}")->assertStatus(200);

        $this->getJson("/api/shared-reports/{$token}")->assertStatus(404);
        $this->postJson("/api/shared-reports/{$token}/unlock", ['password' => self::PASSWORD])->assertStatus(404);

        // And an already-unlocked session dies with it: revocation that
        // waits for a token to expire is not revocation.
        $this->asRecipient()->withHeader('X-Share-Access', $access)
            ->getJson("/api/shared-reports/{$token}/report")
            ->assertStatus(401);
    }

    public function test_an_expired_link_stops_working(): void
    {
        ['token' => $token, 'share' => $share] = $this->createShare();
        $access = $this->unlock($token);

        $share->update(['expires_at' => now()->subMinute()]);

        $this->getJson("/api/shared-reports/{$token}")->assertStatus(404);
        $this->asRecipient()->withHeader('X-Share-Access', $access)
            ->getJson("/api/shared-reports/{$token}/report")
            ->assertStatus(401);
    }

    public function test_regenerating_kills_the_old_url_and_keeps_the_history(): void
    {
        ['token' => $old, 'share' => $share] = $this->createShare();
        $this->unlock($old);

        $response = $this->asOwner()
            ->postJson("/api/audits/{$this->audit->id}/shares/{$share->id}/regenerate")
            ->assertStatus(200);

        $url = $response->json('data.url');
        $new = substr($url, strrpos($url, '/') + 1);

        $this->assertNotSame($old, $new);
        $this->getJson("/api/shared-reports/{$old}")->assertStatus(404);
        $this->getJson("/api/shared-reports/{$new}")->assertStatus(200);

        // Same share, so the record of what the old URL did survives.
        $this->assertSame($share->id, ReportShare::latest('id')->first()->id);
        $this->assertDatabaseHas('report_share_events', [
            'report_share_id' => $share->id,
            'event' => ShareEvent::Viewed->value,
        ]);
    }

    public function test_the_password_can_be_changed_and_the_old_one_stops_working(): void
    {
        ['token' => $token, 'share' => $share] = $this->createShare();

        $this->asOwner()
            ->putJson("/api/audits/{$this->audit->id}/shares/{$share->id}", ['password' => 'a-brand-new-password'])
            ->assertStatus(200);

        $this->postJson("/api/shared-reports/{$token}/unlock", ['password' => self::PASSWORD])->assertStatus(422);
        $this->postJson("/api/shared-reports/{$token}/unlock", ['password' => 'a-brand-new-password'])->assertStatus(200);
    }

    // --- Enumeration ---

    public function test_an_unknown_token_is_indistinguishable_from_a_revoked_one(): void
    {
        ['token' => $token, 'share' => $share] = $this->createShare();
        $this->asOwner()->deleteJson("/api/audits/{$this->audit->id}/shares/{$share->id}");

        $revoked = $this->getJson("/api/shared-reports/{$token}");
        $unknown = $this->getJson('/api/shared-reports/'.str_repeat('a', 64));

        // Telling them apart tells a holder of a leaked URL that they had
        // the right URL.
        $this->assertSame($revoked->status(), $unknown->status());
        $this->assertSame($revoked->json('message'), $unknown->json('message'));
    }

    public function test_a_malformed_token_never_reaches_the_database(): void
    {
        $service = app(ShareTokenService::class);

        $this->assertFalse($service->looksLikeToken('short'));
        $this->assertFalse($service->looksLikeToken(str_repeat('z', 64)));
        $this->assertNull($service->findByToken('../../etc/passwd'));
        $this->assertTrue($service->looksLikeToken($service->generateToken()));
    }

    public function test_tokens_are_unique_and_high_entropy(): void
    {
        $service = app(ShareTokenService::class);
        $tokens = collect(range(1, 50))->map(fn () => $service->generateToken());

        $this->assertCount(50, $tokens->unique());
        $this->assertSame(64, strlen($tokens->first()), '32 bytes of CSPRNG output, hex encoded');
    }

    // --- IDOR ---

    public function test_an_access_token_for_one_share_does_not_unlock_another(): void
    {
        ['token' => $mine] = $this->createShare();
        $myAccess = $this->unlock($mine);

        $other = $this->makeUser('other@example.com', ['report_sharing' => true]);
        $otherAudit = $this->makeAudit($other);
        $response = $this->asOwner($other)
            ->postJson("/api/audits/{$otherAudit->id}/shares", ['password' => 'another-password'])
            ->assertStatus(201);
        $theirUrl = $response->json('data.url');
        $theirToken = substr($theirUrl, strrpos($theirUrl, '/') + 1);

        // A valid access token plus somebody else's URL is the classic
        // IDOR shape. Both must match the same share.
        $this->withHeader('X-Share-Access', $myAccess)
            ->getJson("/api/shared-reports/{$theirToken}/report")
            ->assertStatus(401);
    }

    public function test_a_share_of_one_audit_cannot_read_another_audits_screenshots(): void
    {
        ['token' => $token] = $this->createShare();
        $access = $this->unlock($token);

        $other = $this->makeUser('other2@example.com');
        $otherAudit = $this->makeAudit($other);
        $otherShot = Screenshot::where('audit_id', $otherAudit->id)->first();

        $this->asRecipient()->withHeader('X-Share-Access', $access)
            ->get(app(ScreenshotUrlSigner::class)->urlFor($otherShot))
            ->assertStatus(404);
    }

    public function test_a_forged_access_token_is_refused(): void
    {
        ['token' => $token] = $this->createShare();

        foreach (['not-a-token', base64_encode('{"share_id":1,"expires_at":99999999999}')] as $forged) {
            $this->asRecipient()->withHeader('X-Share-Access', $forged)
                ->getJson("/api/shared-reports/{$token}/report")
                ->assertStatus(401);
        }
    }

    // --- Permissions ---

    public function test_pdf_download_is_refused_when_the_share_disallows_it(): void
    {
        ['token' => $token] = $this->createShare(['allow_pdf' => false]);
        $access = $this->unlock($token);

        $this->asRecipient()->withHeader('X-Share-Access', $access)
            ->get("/api/shared-reports/{$token}/pdf/seo")
            ->assertStatus(403);
    }

    public function test_pdf_download_works_when_the_share_allows_it(): void
    {
        ['token' => $token] = $this->createShare(['allow_pdf' => true]);
        $access = $this->unlock($token);

        $response = $this->asRecipient()->withHeader('X-Share-Access', $access)
            ->get("/api/shared-reports/{$token}/pdf/seo");

        $response->assertStatus(200)->assertHeader('Content-Type', 'application/pdf');
        $this->assertDatabaseHas('report_share_events', ['event' => ShareEvent::PdfDownloaded->value]);
    }

    public function test_a_share_cannot_grant_a_pdf_the_owners_plan_does_not_allow(): void
    {
        $noPdf = $this->makeUser('nopdf@example.com', ['report_sharing' => true, 'pdf_export' => false]);
        $audit = $this->makeAudit($noPdf);

        $url = $this->asOwner($noPdf)
            ->postJson("/api/audits/{$audit->id}/shares", ['password' => self::PASSWORD, 'allow_pdf' => true])
            ->json('data.url');
        $token = substr($url, strrpos($url, '/') + 1);
        $access = $this->unlock($token);

        // A share narrows what the owner can see; it can never widen it.
        $this->asRecipient()->withHeader('X-Share-Access', $access)
            ->get("/api/shared-reports/{$token}/pdf/seo")
            ->assertStatus(403);
    }

    public function test_screenshots_are_withheld_when_the_share_disallows_them(): void
    {
        ['token' => $token] = $this->createShare(['allow_screenshots' => false]);
        $access = $this->unlock($token);

        $shot = Screenshot::where('audit_id', $this->audit->id)->first();

        $this->asRecipient()->withHeader('X-Share-Access', $access)
            ->get(app(ScreenshotUrlSigner::class)->urlFor($shot))
            ->assertStatus(404);

        $pages = $this->asRecipient()->withHeader('X-Share-Access', $access)
            ->getJson("/api/shared-reports/{$token}/pages")
            ->json('data');

        foreach ($pages as $page) {
            $this->assertNull($page['screenshots']['desktop']);
            $this->assertNull($page['screenshots']['mobile']);
        }
    }

    public function test_screenshots_load_when_the_share_allows_them(): void
    {
        ['token' => $token] = $this->createShare(['allow_screenshots' => true]);
        $access = $this->unlock($token);

        $shot = Screenshot::where('audit_id', $this->audit->id)->first();

        $this->asRecipient()->withHeader('X-Share-Access', $access)
            ->get(app(ScreenshotUrlSigner::class)->urlFor($shot))
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_recommendations_are_withheld_when_the_share_disallows_them(): void
    {
        ['token' => $token] = $this->createShare(['allow_recommendations' => false]);
        $access = $this->unlock($token);

        $seo = $this->asRecipient()->withHeader('X-Share-Access', $access)
            ->getJson("/api/shared-reports/{$token}/report")
            ->json('data.services.seo');

        // Emptied rather than removed: a missing key crashes a consumer,
        // an empty one renders as "nothing here".
        $this->assertSame([], $seo['recommendations']);
        $this->assertArrayHasKey('withheld_recommendation_count', $seo);
    }

    // --- Direct API bypass ---

    public function test_a_share_recipient_cannot_use_the_owners_endpoints(): void
    {
        ['token' => $token] = $this->createShare();
        $access = $this->unlock($token);

        // The access token is not an account credential, so it must not
        // *elevate* anything. Compared against a plain anonymous request
        // rather than asserted to be an error: `/report/services` already
        // answers anonymous callers with the free preview (§35), and that
        // is intended product behaviour, not a bypass. What would be a
        // bypass is the share token turning that preview into more.
        foreach ([
            "/api/audits/{$this->audit->id}/report/services",
            "/api/audits/{$this->audit->id}/pages",
            "/api/audits/{$this->audit->id}/shares",
        ] as $ownerRoute) {
            $anonymous = $this->asRecipient()->getJson($ownerRoute);
            $withShareToken = $this->asRecipient()
                ->withHeader('X-Share-Access', $access)
                ->getJson($ownerRoute);

            $this->assertSame(
                $anonymous->status(),
                $withShareToken->status(),
                "{$ownerRoute} answered a share token differently from an anonymous caller",
            );
            $this->assertSame($anonymous->json('data'), $withShareToken->json('data'));
        }

        // And the owner-only routes are still closed to both.
        $this->assertSame(401, $this->asRecipient()->getJson("/api/audits/{$this->audit->id}/shares")->status());
        $this->assertSame(403, $this->asRecipient()->getJson("/api/audits/{$this->audit->id}/pages")->status());
    }

    public function test_the_share_access_token_is_not_accepted_as_a_bearer_token(): void
    {
        ['token' => $token] = $this->createShare();
        $access = $this->unlock($token);

        $this->asRecipient()->withHeader('Authorization', "Bearer {$access}")
            ->getJson("/api/audits/{$this->audit->id}/shares")
            ->assertStatus(401);
    }

    // --- noindex ---

    public function test_every_shared_response_tells_crawlers_to_stay_away(): void
    {
        ['token' => $token] = $this->createShare(['allow_pdf' => true]);
        $access = $this->unlock($token);

        $this->getJson("/api/shared-reports/{$token}")
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->asRecipient()->withHeader('X-Share-Access', $access)
            ->getJson("/api/shared-reports/{$token}/report")
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        // Including the PDF, which is the artifact most likely to be
        // fetched by something that indexes what it finds.
        $this->asRecipient()->withHeader('X-Share-Access', $access)
            ->get("/api/shared-reports/{$token}/pdf/seo")
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    // --- Audit log ---

    public function test_every_named_event_is_recorded(): void
    {
        ['token' => $token, 'share' => $share] = $this->createShare(['allow_pdf' => true]);

        $this->postJson("/api/shared-reports/{$token}/unlock", ['password' => 'wrong']);
        $access = $this->unlock($token);
        $this->asRecipient()->withHeader('X-Share-Access', $access)->get("/api/shared-reports/{$token}/pdf/seo");
        $this->asOwner()->putJson("/api/audits/{$this->audit->id}/shares/{$share->id}", ['password' => 'changed-password-x']);
        $this->asOwner()->deleteJson("/api/audits/{$this->audit->id}/shares/{$share->id}");

        $recorded = ReportShareEvent::pluck('event')->map(fn ($e) => $e->value)->unique();

        foreach ([
            ShareEvent::Created, ShareEvent::PasswordFailed, ShareEvent::Viewed,
            ShareEvent::PdfDownloaded, ShareEvent::PasswordChanged, ShareEvent::Revoked,
        ] as $expected) {
            $this->assertContains($expected->value, $recorded, "{$expected->value} was not recorded");
        }
    }

    public function test_the_owner_can_see_failed_attempts_on_their_share(): void
    {
        ['token' => $token, 'share' => $share] = $this->createShare();

        $this->postJson("/api/shared-reports/{$token}/unlock", ['password' => 'wrong']);
        $this->postJson("/api/shared-reports/{$token}/unlock", ['password' => 'wrong']);

        $status = $this->asOwner()->getJson("/api/audits/{$this->audit->id}/shares")->json('data.shares.0');

        // The signal that a link has reached someone it should not have.
        $this->assertSame(2, $status['failed_attempts']);
    }

    public function test_the_history_survives_the_share_being_deleted(): void
    {
        ['share' => $share] = $this->createShare();
        $share->delete();

        // The events are the audit trail; deleting the subject must not
        // erase the record of what it did.
        $this->assertDatabaseHas('report_share_events', [
            'event' => ShareEvent::Created->value,
            'report_share_id' => null,
        ]);
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('share-password:1');
        parent::tearDown();
    }
}
