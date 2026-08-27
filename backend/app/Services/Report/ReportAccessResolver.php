<?php

namespace App\Services\Report;

use App\Models\Audit;
use App\Models\User;

/**
 * Determines whether a specific viewer gets the full report or the
 * free/anonymous view. Deliberately isolated from ReportVisibilityFilter
 * so this is the only place that needs to change once Phase 6+ evolves
 * plan tiers further.
 *
 * An anonymous audit (no owner) never grants full access to anyone —
 * there's no session/cookie mechanism yet to let its anonymous creator
 * "claim" it, and nothing else has a legitimate claim either. A
 * registered audit only grants full access to its own owner, not to any
 * other authenticated user who happens to know/guess the audit ID.
 *
 * Interim rule (no plan-tier gating yet — see CONTEXT.md §20): being the
 * audit's owner is sufficient for full access, regardless of which plan
 * they're on. Phase 6+ can tighten this to check the owner's plan
 * features (e.g. only paid plans get full access) without touching
 * ReportVisibilityFilter or its callers.
 */
class ReportAccessResolver
{
    public function hasFullAccess(Audit $audit, ?User $viewer): bool
    {
        if ($viewer === null || $audit->user_id === null) {
            return false;
        }

        return $audit->user_id === $viewer->id;
    }
}
