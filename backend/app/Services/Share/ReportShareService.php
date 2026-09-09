<?php

namespace App\Services\Share;

use App\Enums\ShareEvent;
use App\Models\Audit;
use App\Models\ReportShare;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * The owner's side of a share (§50): create it, change it, revoke it.
 *
 * Ownership is checked by the caller (the controller resolves the audit
 * from the user's own records), and the plan entitlement by the caller
 * too — this class assumes both and concerns itself with the mechanics.
 *
 * One rule shapes most of it: **the raw token exists for exactly one
 * response.** Creating or regenerating returns it; nothing else ever can,
 * because only its hash is stored. That is deliberate — a link the system
 * can re-display is a link an attacker with read access can also
 * re-display.
 */
class ReportShareService
{
    public function __construct(
        private readonly ShareTokenService $tokens,
        private readonly ShareAuditLogger $log,
    ) {}

    /**
     * @param  array<string, bool>  $permissions
     * @return array{share: ReportShare, token: string, url: string}
     */
    public function create(
        Audit $audit,
        User $owner,
        string $password,
        array $permissions = [],
        ?\DateTimeInterface $expiresAt = null,
        ?Request $request = null,
    ): array {
        $token = $this->tokens->generateToken();

        $share = ReportShare::create([
            'audit_id' => $audit->id,
            'created_by_user_id' => $owner->id,
            'token_hash' => $this->tokens->hashToken($token),
            'password_hash' => Hash::make($password),
            'allow_pdf' => $permissions['allow_pdf'] ?? false,
            'allow_screenshots' => $permissions['allow_screenshots'] ?? true,
            'allow_recommendations' => $permissions['allow_recommendations'] ?? true,
            'expires_at' => $expiresAt,
        ]);

        $this->log->record(ShareEvent::Created, $share, $request, [
            'allow_pdf' => $share->allow_pdf,
            'expires_at' => $share->expires_at?->toIso8601String(),
        ]);

        return ['share' => $share, 'token' => $token, 'url' => $this->tokens->urlFor($token)];
    }

    public function revoke(ReportShare $share, ?Request $request = null): ReportShare
    {
        // Idempotent: revoking twice is not an error, and re-stamping the
        // time would misreport when access actually stopped.
        if ($share->revoked_at === null) {
            $share->update(['revoked_at' => now()]);
            $this->log->record(ShareEvent::Revoked, $share, $request);
        }

        return $share->fresh();
    }

    /**
     * A new token for the same share, invalidating the old URL.
     *
     * Distinct from revoke-and-create because the *share* survives: its
     * permissions, its password and its history stay attached, so the
     * owner can kill a leaked URL without losing the record of what that
     * URL did.
     *
     * @return array{share: ReportShare, token: string, url: string}
     */
    public function regenerate(ReportShare $share, ?Request $request = null): array
    {
        $token = $this->tokens->generateToken();

        $share->update([
            'token_hash' => $this->tokens->hashToken($token),
            // A regenerated link is a live link again. Regenerating is
            // what an owner does *instead of* leaving it revoked.
            'revoked_at' => null,
        ]);

        $this->log->record(ShareEvent::Regenerated, $share, $request);

        return ['share' => $share->fresh(), 'token' => $token, 'url' => $this->tokens->urlFor($token)];
    }

    public function changePassword(ReportShare $share, string $password, ?Request $request = null): ReportShare
    {
        $share->update(['password_hash' => Hash::make($password)]);

        // The new password is never logged, obviously — but neither is
        // any hint of it. The event is that it changed.
        $this->log->record(ShareEvent::PasswordChanged, $share, $request);

        return $share->fresh();
    }

    /**
     * @param  array<string, bool>  $permissions
     */
    public function updatePermissions(ReportShare $share, array $permissions, ?Request $request = null): ReportShare
    {
        $share->update(array_intersect_key($permissions, array_flip([
            'allow_pdf',
            'allow_screenshots',
            'allow_recommendations',
        ])));

        $this->log->record(ShareEvent::PermissionsChanged, $share->fresh(), $request, $permissions);

        return $share->fresh();
    }

    /**
     * The owner's view of a share. Carries no token and no password — the
     * URL is shown once, at creation, and never reconstructed.
     *
     * @return array<string, mixed>
     */
    public function statusFor(ReportShare $share): array
    {
        return [
            'id' => $share->id,
            'is_active' => $share->isActive(),
            'inactive_reason' => $share->inactiveReason(),
            'allow_pdf' => $share->allow_pdf,
            'allow_screenshots' => $share->allow_screenshots,
            'allow_recommendations' => $share->allow_recommendations,
            'expires_at' => $share->expires_at?->toIso8601String(),
            'revoked_at' => $share->revoked_at?->toIso8601String(),
            'last_viewed_at' => $share->last_viewed_at?->toIso8601String(),
            'view_count' => $share->view_count,
            'created_at' => $share->created_at?->toIso8601String(),
            // The count an owner actually reacts to.
            'failed_attempts' => $share->events()
                ->where('event', ShareEvent::PasswordFailed->value)
                ->count(),
        ];
    }
}
