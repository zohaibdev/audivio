<?php

namespace App\Services\Share;

use App\Enums\ShareEvent;
use App\Models\ReportShare;
use App\Models\ReportShareEvent;
use Illuminate\Http\Request;

/**
 * Records what happened to a share (§50).
 *
 * The owner gave a link to someone outside the system, so this is the
 * only account they will ever have of what that link did. Failed password
 * attempts are the entry that earns the table: a run of them is how an
 * owner learns a link has reached someone it was not meant for.
 *
 * Logging never throws. A share that cannot be written to the log must
 * still work — losing an audit entry is bad, refusing a legitimate
 * recipient because of it is worse.
 */
class ShareAuditLogger
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        ShareEvent $event,
        ?ReportShare $share,
        ?Request $request = null,
        array $context = [],
    ): void {
        try {
            ReportShareEvent::create([
                'report_share_id' => $share?->id,
                'audit_id' => $share?->audit_id,
                'event' => $event->value,
                'ip_address' => $request?->ip(),
                // Truncated: attacker-controlled and unbounded.
                'user_agent' => $request === null ? null : substr((string) $request->userAgent(), 0, 500),
                'context' => $context === [] ? null : $context,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Deliberately swallowed — see the class docblock.
        }
    }
}
