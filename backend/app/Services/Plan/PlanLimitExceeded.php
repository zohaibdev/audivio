<?php

namespace App\Services\Plan;

/**
 * A plan limit stopped an action that is otherwise valid.
 *
 * Deliberately not a validation error: the request was well-formed and the
 * caller is authenticated: they have simply used up an allowance. Carries
 * the limit that was hit so the API can tell the user *which* one, rather
 * than a generic "upgrade your plan".
 */
class PlanLimitExceeded extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $limit,
        public readonly ?int $allowed = null,
    ) {
        parent::__construct($message);
    }

    public static function websites(int $allowed): self
    {
        return new self(
            $allowed === 0
                ? 'Your plan does not include saved websites.'
                : "Your plan covers {$allowed} website".($allowed === 1 ? '' : 's').'. Remove one, or upgrade, to audit another.',
            limit: 'max_websites',
            allowed: $allowed,
        );
    }

    public static function monthlyAudits(int $allowed): self
    {
        return new self(
            "You have used all {$allowed} audits included in your plan this month.",
            limit: 'monthly_audit_limit',
            allowed: $allowed,
        );
    }
}
