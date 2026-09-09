<?php

namespace App\Services\Audit;

use App\Enums\AuditStatus;
use App\Models\Audit;
use App\Models\User;
use App\Services\Plan\EntitlementResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Enforces how many audits one owner may have in flight at once.
 *
 * "In flight" is derived from `audits.status`, never tracked separately:
 * an audit holds a slot exactly while its status is non-terminal. That is
 * the whole reason completed, failed and cancelled audits release their
 * slot without any release step that a new failure path could forget to
 * call — there is no counter to decrement.
 *
 * ## Why the locking is layered
 *
 * Count-then-insert is a textbook race: two requests both count N-1
 * active audits, both decide there is room, and the account ends up with
 * N+1. Neither guard below is sufficient alone:
 *
 * - **The named lock** (`Cache::lock`) serializes attempts by the same
 *   owner across *processes*, which is what actually matters when two
 *   PHP-FPM workers handle two requests simultaneously. On Redis (what
 *   production runs) this is atomic cluster-wide. It is acquired
 *   non-blocking: a second simultaneous request is refused outright
 *   rather than queued behind the first, because the first is about to
 *   take the very slot it would be waiting for.
 * - **The transaction with `lockForUpdate()`** serializes at the database
 *   for registered users, so the limit still holds if the cache is
 *   flushed, the lock driver is misconfigured, or a second application
 *   instance is deployed with a different cache.
 *
 * A guest has no row to lock, so the named lock is the only guard there —
 * a deliberate, documented gap, and an acceptable one: the anonymous
 * limit is already best-effort (it is keyed on an IP address, which one
 * person can trivially change).
 */
class ActiveAuditLimiter
{
    public function __construct(
        private readonly EntitlementResolver $entitlements,
    ) {}

    /**
     * How long a held lock survives if the process holding it dies before
     * releasing. Short because the work under it is two queries; the only
     * job of this TTL is to stop a crashed request from locking an account
     * out of starting audits.
     */
    private const LOCK_TTL_SECONDS = 5;

    /**
     * Run $callback only if the owner has a free slot, holding a lock for
     * that owner across the whole check-and-create.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback  creates the audit; runs only when there is room
     * @return T|null null when the owner is already at their limit
     */
    public function withSlot(?User $user, ?string $ipAddress, \Closure $callback): mixed
    {
        $lock = Cache::lock($this->lockKey($user, $ipAddress), self::LOCK_TTL_SECONDS);

        // Non-blocking on purpose. A blocking acquire would make the
        // request hang while another of this owner's requests finishes,
        // and `block()`'s timeout is not honoured by every cache driver —
        // the array driver waits out the holder's full TTL instead. There
        // is also nothing to gain by waiting: whoever holds the lock is
        // about to consume the slot being waited for, so the honest answer
        // is "you already have one starting", returned immediately.
        if (! $lock->get()) {
            return null;
        }

        try {
            if ($user === null) {
                return $this->hasCapacity($user, $ipAddress) ? $callback() : null;
            }

            return DB::transaction(function () use ($user, $ipAddress, $callback) {
                // Second guard: take a row lock on the owner so a
                // concurrent transaction blocks here rather than counting
                // stale rows. On SQLite (tests) this is a no-op, which is
                // exactly why the named lock above is not optional.
                User::whereKey($user->id)->lockForUpdate()->first();

                return $this->hasCapacity($user, $ipAddress) ? $callback() : null;
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether the owner has room for one more audit right now.
     *
     * Safe to call outside the lock for read-only purposes (showing "1 of
     * 2 audits running" in the UI). Do not use it to gate creation —
     * that is what withSlot() is for.
     */
    public function hasCapacity(?User $user, ?string $ipAddress): bool
    {
        return $this->activeCount($user, $ipAddress) < $this->limitFor($user);
    }

    public function activeCount(?User $user, ?string $ipAddress): int
    {
        $query = Audit::query()->whereIn('status', AuditStatus::activeValues());

        if ($user !== null) {
            return $query->where('user_id', $user->id)->count();
        }

        // A guest is identified by the IP its audits were created from.
        // `usage_logs` is where that association lives (audits themselves
        // carry no IP), so the count joins through it.
        if ($ipAddress === null) {
            return 0;
        }

        return $query
            ->whereNull('user_id')
            ->whereIn('id', function ($sub) use ($ipAddress) {
                $sub->select('audit_id')
                    ->from('usage_logs')
                    ->where('ip_address', $ipAddress)
                    ->whereNotNull('audit_id');
            })
            ->count();
    }

    /**
     * Read from the caller's entitlements (section 40), which is what makes
     * a guest, an active subscriber, and a lapsed one each get the right
     * number without this class knowing the difference. A registered user
     * with no subscription row at all resolves to null and gets 0 —
     * consistent with UsageLimitService, where such a user cannot create
     * audits at all.
     */
    public function limitFor(?User $user): int
    {
        return $this->entitlements->for($user)?->concurrentAuditLimit ?? 0;
    }

    private function lockKey(?User $user, ?string $ipAddress): string
    {
        return $user !== null
            ? "active-audits:user:{$user->id}"
            : 'active-audits:ip:'.sha1((string) $ipAddress);
    }
}
