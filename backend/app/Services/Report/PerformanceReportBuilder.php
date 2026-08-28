<?php

namespace App\Services\Report;

use App\Enums\MetricSource;
use App\Enums\MetricStrategy;
use App\Models\Audit;
use App\Models\AuditPage;
use App\Models\PagePerformanceMetric;
use App\Models\Screenshot;

/**
 * The performance-specific detail that sits alongside the standard
 * service-report shape (§35): Core Web Vitals with real thresholds,
 * mobile and desktop kept strictly apart, a measured resource breakdown,
 * and per-page metrics.
 *
 * Every figure here comes from a stored PagePerformanceMetric row, which
 * always carries its own source and strategy. Nothing is estimated,
 * defaulted, or carried across from another page or strategy — a metric
 * that was not measured comes back null and renders as "unavailable".
 */
class PerformanceReportBuilder
{
    /**
     * Metric key => [label, unit, lower-is-better]. INP is deliberately
     * absent: it is a field metric that needs real user data, and
     * Lighthouse lab runs do not produce it. TBT is included instead as
     * the lab-measurable proxy, labelled as what it actually is.
     */
    private const METRICS = [
        'lcp_ms' => ['label' => 'Largest Contentful Paint', 'unit' => 'ms', 'about' => 'How long until the biggest thing on screen has loaded.'],
        'cls' => ['label' => 'Cumulative Layout Shift', 'unit' => '', 'about' => 'How much the page jumps around while it loads.'],
        'tbt_ms' => ['label' => 'Total Blocking Time', 'unit' => 'ms', 'about' => 'How long the page was frozen and unable to respond to taps or clicks.'],
        'fcp_ms' => ['label' => 'First Contentful Paint', 'unit' => 'ms', 'about' => 'How long until the visitor sees anything at all.'],
        'ttfb_ms' => ['label' => 'Time to First Byte', 'unit' => 'ms', 'about' => 'How long your server took to start responding.'],
        'speed_index_ms' => ['label' => 'Speed Index', 'unit' => 'ms', 'about' => 'How quickly the page visibly fills in.'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function build(Audit $audit): array
    {
        $audit->loadMissing(['pages.performanceMetrics', 'pages.screenshots']);

        return [
            // Mobile first, always — it is the primary audit priority, so
            // it leads the payload as well as the UI.
            'mobile' => $this->strategyBlock($audit, MetricStrategy::Mobile),
            'desktop' => $this->strategyBlock($audit, MetricStrategy::Desktop),
            'resources' => $this->resourceTotals($audit),
            'pages' => $this->pageDetails($audit),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function strategyBlock(Audit $audit, MetricStrategy $strategy): array
    {
        // Lighthouse is the only source of a performance *score* and of
        // CWV; the crawler contributes TTFB/FCP for the desktop load it
        // actually performed. Both are looked up by explicit strategy.
        $lighthouse = $this->metricFor($audit, MetricSource::PageSpeed, $strategy);
        $crawler = $strategy === MetricStrategy::Desktop
            ? $this->metricFor($audit, MetricSource::Crawler, $strategy)
            : null;

        if ($lighthouse === null && $crawler === null) {
            return [
                'available' => false,
                'reason' => $strategy === MetricStrategy::Mobile
                    ? 'No mobile performance measurement is available for this audit. Configure the PageSpeed provider and re-run the audit.'
                    : 'No desktop performance measurement is available for this audit. Desktop analysis is off by default — enable it in the PageSpeed provider settings.',
                'score' => null,
                'vitals' => [],
            ];
        }

        return [
            'available' => true,
            'reason' => null,
            'score' => $lighthouse?->score,
            'score_source' => $lighthouse?->score !== null ? MetricSource::PageSpeed->value : null,
            'vitals' => $this->vitals($lighthouse, $crawler),
        ];
    }

    /**
     * Builds one entry per Core Web Vital, each carrying its measured
     * value, threshold, status and the source it came from — so a reader
     * can always see *why* something is rated the way it is.
     *
     * @return array<int, array<string, mixed>>
     */
    private function vitals(?PagePerformanceMetric $lighthouse, ?PagePerformanceMetric $crawler): array
    {
        $thresholds = config('audit_scoring.performance.vitals');
        $vitals = [];

        foreach (self::METRICS as $key => $meta) {
            // Prefer Lighthouse (it measures under a throttled device
            // profile); fall back to the crawler only for metrics it
            // genuinely measured, and record which one was used.
            $value = $lighthouse?->{$key};
            $source = $value !== null ? MetricSource::PageSpeed->value : null;

            if ($value === null && $crawler !== null && in_array($key, ['ttfb_ms', 'fcp_ms'], true)) {
                $value = $crawler->{$key};
                $source = $value !== null ? MetricSource::Crawler->value : null;
            }

            $bounds = $thresholds[$key] ?? null;

            $vitals[] = [
                'key' => $key,
                'label' => $meta['label'],
                'unit' => $meta['unit'],
                'about' => $meta['about'],
                'value' => $value,
                'source' => $source,
                'good_below' => $bounds['good'] ?? null,
                'poor_above' => $bounds['poor'] ?? null,
                'status' => $this->vitalStatus($value, $bounds),
            ];
        }

        return $vitals;
    }

    /**
     * @param  array{good: float, poor: float}|null  $bounds
     */
    private function vitalStatus(int|float|null $value, ?array $bounds): string
    {
        if ($value === null || $bounds === null) {
            return 'unavailable';
        }

        return match (true) {
            $value <= $bounds['good'] => 'good',
            $value <= $bounds['poor'] => 'needs_improvement',
            default => 'poor',
        };
    }

    /**
     * Site-wide resource totals, summed across every page that has a
     * crawler measurement. Pages without one are excluded from the sum
     * rather than counted as zero, and the count of what was actually
     * summed is returned so the figure can be read honestly.
     *
     * @return array<string, mixed>
     */
    private function resourceTotals(Audit $audit): array
    {
        $metrics = $audit->pages
            ->flatMap(fn (AuditPage $p) => $p->performanceMetrics)
            ->filter(fn (PagePerformanceMetric $m) => $m->source === MetricSource::Crawler);

        if ($metrics->isEmpty()) {
            return ['available' => false, 'measured_pages' => 0, 'by_type' => [], 'totals' => []];
        }

        $byType = [];
        foreach ($metrics as $metric) {
            foreach ($metric->by_type ?? [] as $type => $data) {
                $byType[$type]['requests'] = ($byType[$type]['requests'] ?? 0) + ($data['requests'] ?? 0);
                $byType[$type]['bytes'] = ($byType[$type]['bytes'] ?? 0) + ($data['bytes'] ?? 0);
            }
        }

        arsort($byType);

        return [
            'available' => true,
            'measured_pages' => $metrics->count(),
            'by_type' => collect($byType)
                ->map(fn (array $d, string $type) => ['type' => $type, 'requests' => $d['requests'], 'bytes' => $d['bytes']])
                ->sortByDesc('bytes')
                ->values()
                ->all(),
            'totals' => [
                'requests' => $metrics->sum('total_requests'),
                'transfer_bytes' => $metrics->sum('total_transfer_bytes'),
                'third_party_requests' => $metrics->sum('third_party_requests'),
                'third_party_bytes' => $metrics->sum('third_party_bytes'),
                'avg_requests_per_page' => (int) round($metrics->avg('total_requests') ?? 0),
                'avg_transfer_bytes_per_page' => (int) round($metrics->avg('total_transfer_bytes') ?? 0),
            ],
        ];
    }

    /**
     * Per-page metrics, mobile and desktop side by side but never merged.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pageDetails(Audit $audit): array
    {
        $rows = [];

        foreach ($audit->pages as $page) {
            $crawler = $page->performanceMetrics->firstWhere(fn ($m) => $m->source === MetricSource::Crawler);
            $mobile = $page->performanceMetrics->first(
                fn ($m) => $m->source === MetricSource::PageSpeed && $m->strategy === MetricStrategy::Mobile
            );
            $desktop = $page->performanceMetrics->first(
                fn ($m) => $m->source === MetricSource::PageSpeed && $m->strategy === MetricStrategy::Desktop
            );

            if ($crawler === null && $mobile === null && $desktop === null) {
                continue;
            }

            $rows[] = [
                'url' => $page->url,
                'title' => $page->title,
                'mobile_score' => $mobile?->score,
                'desktop_score' => $desktop?->score,
                'lcp_ms' => $mobile?->lcp_ms ?? $desktop?->lcp_ms,
                'cls' => $mobile?->cls ?? $desktop?->cls,
                'tbt_ms' => $mobile?->tbt_ms ?? $desktop?->tbt_ms,
                'ttfb_ms' => $mobile?->ttfb_ms ?? $crawler?->ttfb_ms,
                'fcp_ms' => $mobile?->fcp_ms ?? $crawler?->fcp_ms,
                'speed_index_ms' => $mobile?->speed_index_ms,
                'total_requests' => $crawler?->total_requests,
                'transfer_bytes' => $crawler?->total_transfer_bytes,
                'third_party_bytes' => $crawler?->third_party_bytes,
                'dom_nodes' => $crawler?->dom_nodes,
                'by_type' => $crawler?->by_type,
                'screenshots' => $this->screenshotsFor($page),
            ];
        }

        // Worst mobile score first; pages with no score sink to the bottom
        // rather than sorting as if they scored zero.
        usort($rows, fn (array $a, array $b) => ($a['mobile_score'] ?? 101) <=> ($b['mobile_score'] ?? 101));

        return $rows;
    }

    private function metricFor(Audit $audit, MetricSource $source, MetricStrategy $strategy): ?PagePerformanceMetric
    {
        // The homepage is the representative page: PageSpeed only runs
        // against a capped subset (default: the homepage), so the
        // site-level panel reports that page's numbers rather than
        // averaging measurements that may not exist for other pages.
        foreach ($audit->pages as $page) {
            $match = $page->performanceMetrics->first(
                fn (PagePerformanceMetric $m) => $m->source === $source && $m->strategy === $strategy
            );

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /**
     * @return array<string, string|null>
     */
    private function screenshotsFor(AuditPage $page): array
    {
        $byViewport = $page->screenshots->keyBy(fn (Screenshot $s) => $s->viewport->value);

        return [
            'desktop' => $byViewport->get('desktop')?->storage_path,
            'mobile' => $byViewport->get('mobile')?->storage_path,
        ];
    }
}
