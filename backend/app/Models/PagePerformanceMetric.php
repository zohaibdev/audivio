<?php

namespace App\Models;

use App\Enums\MetricSource;
use App\Enums\MetricStrategy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One performance measurement for one page, from one source, at one
 * device strategy. The (audit_page_id, source, strategy) unique key is
 * what makes "never show a desktop number as mobile" a schema guarantee
 * rather than a convention.
 *
 * Every metric column is nullable on purpose: a tool that did not report a
 * value leaves null, which the report renders as "unavailable". Defaulting
 * to 0 would be indistinguishable from a real measurement of zero.
 */
class PagePerformanceMetric extends Model
{
    protected $fillable = [
        'audit_id',
        'audit_page_id',
        'source',
        'strategy',
        'score',
        'lcp_ms',
        'cls',
        'tbt_ms',
        'fcp_ms',
        'ttfb_ms',
        'speed_index_ms',
        'total_requests',
        'total_transfer_bytes',
        'third_party_requests',
        'third_party_bytes',
        'dom_nodes',
        'by_type',
    ];

    protected function casts(): array
    {
        return [
            'source' => MetricSource::class,
            'strategy' => MetricStrategy::class,
            'cls' => 'float',
            'by_type' => 'array',
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
}
