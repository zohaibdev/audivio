<?php

namespace App\Services\Share;

use App\Models\ReportShare;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Tokens and unlocked sessions for shared reports (§50).
 *
 * Two different secrets, doing two different jobs:
 *
 * **The share token** is the long-lived one in the URL. Generated from
 * `random_bytes()` — a CSPRNG, never `rand()`/`uniqid()`/a hash of
 * anything predictable — and stored only as a SHA-256 hash, so the
 * database cannot be turned back into working links. It identifies which
 * share is being opened. It authorizes nothing on its own.
 *
 * **The access token** is issued only after the password is verified. It
 * is an encrypted, expiring statement that says "this browser satisfied
 * the password for share N". Stateless, so no session table and no
 * cleanup — but every request that presents one still re-loads the share
 * and re-checks that it is active, which is what makes revocation
 * immediate rather than eventual.
 *
 * The URL never contains the audit id. That is not decoration: a numeric
 * id in a shared URL is an invitation to change it, and the whole point
 * of a capability URL is that it grants exactly one thing.
 */
class ShareTokenService
{
    /**
     * A fresh token. Returned raw exactly once — the caller must hand it
     * to the owner immediately, because nothing can recover it later.
     */
    public function generateToken(): string
    {
        return bin2hex(random_bytes((int) config('report_sharing.token_bytes')));
    }

    /**
     * Deterministic, so a presented token is one indexed lookup.
     *
     * SHA-256 without a salt is correct here and would be wrong for a
     * password: the input already carries 256 bits of entropy, so there
     * is no dictionary to precompute and nothing a salt would defend.
     */
    public function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function findByToken(string $token): ?ReportShare
    {
        // Rejected before touching the database: a token of the wrong
        // shape cannot match anything, and short-circuiting keeps
        // enumeration attempts off the query path entirely.
        if (! $this->looksLikeToken($token)) {
            return null;
        }

        return ReportShare::where('token_hash', $this->hashToken($token))->first();
    }

    public function looksLikeToken(string $token): bool
    {
        $expected = (int) config('report_sharing.token_bytes') * 2;

        return strlen($token) === $expected && ctype_xdigit($token);
    }

    public function urlFor(string $token): string
    {
        $path = str_replace('{token}', $token, (string) config('report_sharing.url_template'));

        return rtrim((string) config('app.frontend_url', config('app.url')), '/').$path;
    }

    /**
     * Mint proof that this share's password was satisfied.
     *
     * Encrypted rather than signed, so the share id is not even readable
     * from the token — there is no reason for a recipient's browser to
     * learn an internal identifier.
     */
    public function issueAccessToken(ReportShare $share): string
    {
        return Crypt::encryptString(json_encode([
            'share_id' => $share->id,
            'expires_at' => now()->addMinutes((int) config('report_sharing.access_ttl_minutes'))->timestamp,
        ]));
    }

    /**
     * The share this access token unlocks, or null.
     *
     * Returns null for every failure mode — tampered, expired, or
     * pointing at a share that has since been revoked — because the
     * caller's response must be identical in each case. A recipient who
     * can tell "expired" from "revoked" from "forged" is being told
     * something about a link they may not be entitled to hold.
     */
    public function shareFromAccessToken(?string $accessToken): ?ReportShare
    {
        if ($accessToken === null || $accessToken === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($accessToken), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($payload) || ! isset($payload['share_id'], $payload['expires_at'])) {
            return null;
        }

        if ((int) $payload['expires_at'] < now()->timestamp) {
            return null;
        }

        $share = ReportShare::find($payload['share_id']);

        // Re-checked here, not just at unlock. Revoking a link has to take
        // effect for someone already holding an unlocked session.
        return $share !== null && $share->isActive() ? $share : null;
    }
}
