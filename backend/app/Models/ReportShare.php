<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A password-protected link to one audit's report (§50).
 *
 * Holds no plaintext secret: the token is stored as a SHA-256 hash and
 * the password as a bcrypt hash, so this row cannot be turned back into
 * a working link. The raw token exists exactly once, in the response to
 * the request that created or regenerated it — after that it lives only
 * in whatever the owner pasted it into.
 */
class ReportShare extends Model
{
    protected $fillable = [
        'audit_id',
        'created_by_user_id',
        'token_hash',
        'password_hash',
        'allow_pdf',
        'allow_screenshots',
        'allow_recommendations',
        'expires_at',
        'revoked_at',
        'last_viewed_at',
        'view_count',
    ];

    /**
     * Both hashes are hidden from every serialization by default. Neither
     * is directly exploitable, but a password hash reaching a JSON
     * response is an offline cracking target that never needed to leave
     * the server.
     */
    protected $hidden = [
        'token_hash',
        'password_hash',
    ];

    protected function casts(): array
    {
        return [
            'allow_pdf' => 'boolean',
            'allow_screenshots' => 'boolean',
            'allow_recommendations' => 'boolean',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_viewed_at' => 'datetime',
        ];
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ReportShareEvent::class);
    }

    /**
     * Whether this link still works.
     *
     * Checked on **every** request rather than only at unlock: a share
     * revoked while someone has it open must stop working immediately,
     * not when their access token happens to expire.
     */
    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /**
     * Why it does not work, for the owner's status view. Never shown to a
     * recipient — "this link was revoked" tells someone holding a leaked
     * URL that they had the right URL.
     */
    public function inactiveReason(): ?string
    {
        if ($this->revoked_at !== null) {
            return 'Revoked '.$this->revoked_at->diffForHumans();
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return 'Expired '.$this->expires_at->diffForHumans();
        }

        return null;
    }
}
