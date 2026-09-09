<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateWebsiteRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Website;
use App\Services\Website\WebsiteCardBuilder;
use App\Services\Website\WebsiteDetailBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service "my websites" — distinct from
 * Api\Admin\AdminWebsiteController, which lists every website across every
 * user. Requires auth:sanctum (unlike audit creation/status, which are
 * anonymous-friendly): an anonymous visitor has no persistent identity to
 * list websites against.
 *
 * **Every lookup here starts from `$request->user()->websites()`.** The
 * frontend addresses a website by domain (`/dashboard/websites?website=
 * example.com`), and a domain is public knowledge — it identifies *which*
 * of the caller's websites to show, and never grants access to it. A
 * website belonging to someone else is not "forbidden", it is simply not
 * in the set being queried, so it 404s exactly as a domain the caller has
 * never audited would. That is the point: the response cannot be used to
 * discover which domains other users have audited.
 */
class WebsiteController extends Controller
{
    public function __construct(
        private readonly WebsiteCardBuilder $cards,
        private readonly WebsiteDetailBuilder $details,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $websites = $request->user()->websites()
            ->withCount('audits')
            ->orderByDesc('id')
            ->paginate(25);

        // The paginator's own envelope (page/total/links) is preserved —
        // only each row is replaced with its card shape.
        return ApiResponse::success(
            tap($websites, fn ($page) => $page->setCollection(
                collect($this->cards->buildMany($page->getCollection()))
            )),
        );
    }

    public function show(Request $request, string $website): JsonResponse
    {
        $model = $this->findForUser($request, $website);

        if ($model === null) {
            return ApiResponse::error('Website not found.', 404);
        }

        return ApiResponse::success($this->details->build($model));
    }

    public function update(UpdateWebsiteRequest $request, string $website): JsonResponse
    {
        $model = $this->findForUser($request, $website);

        if ($model === null) {
            return ApiResponse::error('Website not found.', 404);
        }

        // Only the profile fields are writable. `domain`,
        // `normalized_domain` and `primary_url` are derived from what was
        // actually audited, and letting a user edit them would let one
        // website record claim another's identity — and, with the unique
        // index, collide with a record they already own.
        $model->update($request->validated());

        return ApiResponse::success($this->details->build($model->fresh()));
    }

    /**
     * Resolve the `{website}` segment against the caller's own websites,
     * by numeric id or by normalized domain.
     *
     * Accepting a domain is what lets the frontend keep a shareable,
     * readable URL. It is safe precisely because the query it resolves
     * against is already scoped to the authenticated user — the segment
     * selects, the scope authorizes.
     */
    private function findForUser(Request $request, string $website): ?Website
    {
        $query = $request->user()->websites();

        if (ctype_digit($website)) {
            return $query->find((int) $website);
        }

        return $query->where('normalized_domain', $this->normalizeSegment($website))->first();
    }

    /**
     * Reduce a `{website}` segment to the same form
     * `websites.normalized_domain` holds, so `WWW.Example.com.` and
     * `example.com` find the one record rather than the second missing it.
     *
     * A bare domain, not a URL: a full URL cannot survive as a single path
     * segment anyway (its slashes have to be percent-encoded, and the
     * router rejects that), and the frontend addresses websites by domain
     * — `/dashboard/websites?website=example.com`.
     */
    private function normalizeSegment(string $segment): string
    {
        $segment = rtrim(strtolower(trim($segment)), '.');

        return str_starts_with($segment, 'www.') ? substr($segment, 4) : $segment;
    }
}
