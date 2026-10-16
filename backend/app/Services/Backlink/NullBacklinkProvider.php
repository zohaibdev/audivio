<?php

namespace App\Services\Backlink;

use App\Contracts\BacklinkProvider;
use App\DTOs\BacklinkData;

/**
 * The default provider: there isn't one (§44).
 *
 * This exists so that "no backlink provider is configured" is a normal,
 * fully-supported state of the product rather than a missing binding that
 * throws somewhere deep in an audit. It answers null, always, and the
 * report renders "no backlink data" — which is the truth.
 */
class NullBacklinkProvider implements BacklinkProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function fetch(string $domain): ?BacklinkData
    {
        return null;
    }
}
