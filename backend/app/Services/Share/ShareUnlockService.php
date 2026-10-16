<?php

namespace App\Services\Share;

use App\Enums\ShareEvent;
use App\Models\ReportShare;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Verifying a share password, and refusing to keep answering (§50).
 *
 * Two limits, at deliberately different heights:
 *
 * - **Per IP and token** (default 5): stops one attacker guessing. This
 *   is the limit that does the real work.
 * - **Per token, all callers** (10x that): the backstop against a
 *   distributed attempt spread across addresses, set far above anything
 *   legitimate use reaches.
 *
 * The honest limitation: a determined attacker *can* trip the token-wide
 * limit and lock the real recipient out for the window. Any global
 * counter has that property — the alternative is no defence against
 * distributed guessing at all. It is mitigated rather than solved: the
 * ceiling is high enough that it takes real effort, the owner sees the
 * failed attempts in the share's status, and revoking or regenerating
 * the link is a click away.
 */
class ShareUnlockService
{
    public function __construct(private readonly ShareAuditLogger $log) {}

    /**
     * @return array{ok: bool, reason: ?string, retry_after: ?int}
     */
    public function attempt(ReportShare $share, string $password, Request $request): array
    {
        $limits = $this->limitsFor($share, $request);

        foreach ($limits as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $seconds = RateLimiter::availableIn($key);

                // Logged once per lockout rather than per attempt, so the
                // owner's view shows "they were locked out" instead of a
                // wall of identical rows.
                $this->log->record(ShareEvent::LockedOut, $share, $request, ['retry_after' => $seconds]);

                return ['ok' => false, 'reason' => 'locked', 'retry_after' => $seconds];
            }
        }

        if (! Hash::check($password, $share->password_hash)) {
            $decay = (int) config('report_sharing.lockout.decay_minutes') * 60;

            foreach ($limits as [$key]) {
                RateLimiter::hit($key, $decay);
            }

            $this->log->record(ShareEvent::PasswordFailed, $share, $request);

            return ['ok' => false, 'reason' => 'invalid', 'retry_after' => null];
        }

        // Cleared on success so a recipient who mistyped twice and then
        // got it right is not carrying those failures toward a lockout
        // for the rest of the window.
        foreach ($limits as [$key]) {
            RateLimiter::clear($key);
        }

        $share->forceFill([
            'last_viewed_at' => now(),
            'view_count' => $share->view_count + 1,
        ])->save();

        $this->log->record(ShareEvent::Viewed, $share, $request);

        return ['ok' => true, 'reason' => null, 'retry_after' => null];
    }

    /**
     * @return array<int, array{0: string, 1: int}> [key, max attempts]
     */
    private function limitsFor(ReportShare $share, Request $request): array
    {
        $max = (int) config('report_sharing.lockout.max_attempts');

        return [
            ['share-password:'.$share->id.':'.$request->ip(), $max],
            ['share-password:'.$share->id, $max * 10],
        ];
    }
}
