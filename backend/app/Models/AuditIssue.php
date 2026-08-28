<?php

namespace App\Models;

use App\Enums\AuditCategory;
use App\Enums\IssueSeverity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AuditIssue extends Model
{
    protected $fillable = [
        'audit_id',
        'audit_page_id',
        'category',
        'severity',
        'code',
        'message',
        'recommendation',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'category' => AuditCategory::class,
            'severity' => IssueSeverity::class,
            'meta' => 'array',
        ];
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    public function auditPage(): BelongsTo
    {
        return $this->belongsTo(AuditPage::class);
    }

    /**
     * The full set of pages this issue affects, for an issue that spans
     * more than one page — a single-page issue uses auditPage()/
     * audit_page_id directly instead (see PerPageIssueAggregator).
     */
    public function pages(): BelongsToMany
    {
        return $this->belongsToMany(AuditPage::class, 'issue_pages')->withTimestamps();
    }
}
