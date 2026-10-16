<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The unauthenticated attack surface (§56).
 *
 * These are structural tests rather than behavioural ones: they walk the
 * router and assert a property of *every* route, so a route added later
 * without a rate limit fails here rather than being noticed in production.
 * A test that checks one endpoint would not have caught the five that were
 * unthrottled when this was written.
 */
class PublicSurfaceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, array{uri: string, methods: string, middleware: string}>
     */
    private function publicApiRoutes(): array
    {
        $public = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/')) {
                continue;
            }

            $middleware = implode(',', array_filter($route->gatherMiddleware(), 'is_string'));

            if (str_contains($middleware, 'auth:sanctum') || str_contains($middleware, 'admin')) {
                continue;
            }

            $public[] = [
                'uri' => $uri,
                'methods' => implode('|', $route->methods()),
                'middleware' => $middleware,
            ];
        }

        return $public;
    }

    public function test_every_unauthenticated_api_route_is_rate_limited(): void
    {
        $unthrottled = [];

        foreach ($this->publicApiRoutes() as $route) {
            if (! str_contains($route['middleware'], 'throttle:')) {
                $unthrottled[] = "{$route['methods']} {$route['uri']}";
            }
        }

        // An unauthenticated endpoint with no ceiling is the cheapest thing
        // in the app to abuse — no account, no token, no cost to the
        // caller. "It's only a read" is not a defence: every one of these
        // runs real queries, and EntitlementResolver is deliberately never
        // cached (§40).
        $this->assertSame(
            [],
            $unthrottled,
            "These routes are reachable without authentication and have no rate limit:\n  ".
            implode("\n  ", $unthrottled),
        );
    }

    public function test_the_public_surface_has_not_quietly_grown(): void
    {
        $uris = array_unique(array_column($this->publicApiRoutes(), 'uri'));
        sort($uris);

        $expected = [
            'api/audits',
            'api/audits/{audit}',
            'api/audits/{audit}/cancel',
            'api/audits/{audit}/comparison',
            'api/audits/{audit}/pages',
            'api/audits/{audit}/report',
            'api/audits/{audit}/report/pdf',
            'api/audits/{audit}/report/services',
            'api/audits/{audit}/report/services/{service}',
            'api/audits/{audit}/report/services/{service}/pdf',
            'api/cms/{slug}',
            'api/leads',
            'api/login',
            'api/me/ai-models',
            'api/me/entitlements',
            'api/me/features',
            'api/plans',
            'api/register',
            'api/screenshots/{screenshot}',
            'api/shared-reports/{token}',
            'api/shared-reports/{token}/pages',
            'api/shared-reports/{token}/pdf/{service}',
            'api/shared-reports/{token}/report',
            'api/shared-reports/{token}/unlock',
        ];

        // Deliberately a fixed list. Every entry here was reasoned about:
        // the audit routes resolve their own viewer and withhold detail
        // from strangers (§9, §35), the shared-report routes are guarded by
        // token and password (§50), and screenshots require a signature
        // (§48). A new name appearing in this diff is a prompt to do that
        // reasoning again, not to update the list.
        $this->assertSame($expected, array_values($uris));
    }

    public function test_a_public_read_endpoint_actually_returns_429_past_its_limit(): void
    {
        // The structural test above proves the middleware is attached; this
        // proves it is a real limit and not a misspelled one that silently
        // does nothing.
        $status = 200;

        for ($i = 0; $i < 65 && $status !== 429; $i++) {
            $status = $this->getJson('/api/plans')->status();
        }

        $this->assertSame(429, $status);
    }
}
