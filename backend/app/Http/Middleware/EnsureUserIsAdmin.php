<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the /api/admin/* route group. Applied after auth:sanctum, so
 * $request->user() is guaranteed non-null here — this only adds the
 * is_admin check on top.
 */
class EnsureUserIsAdmin
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_admin) {
            return ApiResponse::error('This action requires admin access.', 403);
        }

        return $next($request);
    }
}
