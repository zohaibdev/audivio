<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The immutable record of what an audit found and what produced it.
 *
 * Distinct from `reports`, which is a *presentation* of the current data
 * and is regenerated whenever the report format changes. A snapshot is
 * evidence: it is written once when the audit completes and never
 * changes, so "what did this site score in August, and with which scoring
 * weights and which AI model" stays answerable after every one of those
 * things has moved on.
 *
 * Immutability is enforced here rather than left as a convention, because
 * the value of a snapshot is entirely in the guarantee that nothing
 * rewrote it.
 */
class AuditSnapshot extends Model
{
    protected $fillable = [
        'audit_id',
        'website_url',
        'domain',
        'crawler_version',
        'scoring_version',
        'prompt_version',
        'ai_provider',
        'ai_model',
        'pages_analyzed',
        'audited_at',
        'content',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'audited_at' => 'datetime',
            'pages_analyzed' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Blocks `save()` on an existing row, which covers update(),
        // fill()->save(), and attribute assignment followed by save().
        static::updating(function (self $snapshot) {
            throw new AuditSnapshotIsImmutable(
                "Audit snapshot #{$snapshot->id} cannot be modified; snapshots are written once."
            );
        });

        // A delete would be a subtler way to rewrite history: drop the
        // snapshot, re-run the audit, write a new one. Snapshots go only
        // when their audit does, via the FK cascade — which does not fire
        // model events, so this guard does not block that path.
        static::deleting(function (self $snapshot) {
            throw new AuditSnapshotIsImmutable(
                "Audit snapshot #{$snapshot->id} cannot be deleted; it is removed only with its audit."
            );
        });
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }
}
