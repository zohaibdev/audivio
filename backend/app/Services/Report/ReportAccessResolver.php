<?php

namespace App\Services\Report;

use App\Models\Audit;

/**
 * Determines whether a viewer gets the full report or the free/anonymous
 * view. Deliberately isolated from ReportVisibilityFilter so this is the
 * only place that needs to change once Phase 6 (User System) adds real
 * plans/subscriptions.
 *
 * Interim rule (no plans/subscriptions exist yet): any audit tied to a
 * registered user gets full access. This does NOT yet distinguish a free
 * registered user from a paid one per the spec's actual tiers — that
 * split requires the `plans`/`subscriptions` tables Phase 6 introduces.
 */
class ReportAccessResolver
{
    public function hasFullAccess(Audit $audit): bool
    {
        return $audit->user_id !== null;
    }
}
