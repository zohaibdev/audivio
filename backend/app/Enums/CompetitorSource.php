<?php

namespace App\Enums;

enum CompetitorSource: string
{
    /** Named by the owner during onboarding. */
    case User = 'user';

    /** Suggested by CompetitorDiscoveryService. */
    case Discovered = 'discovered';

    public function label(): string
    {
        return match ($this) {
            self::User => 'Added by you',
            self::Discovered => 'Suggested',
        };
    }
}
