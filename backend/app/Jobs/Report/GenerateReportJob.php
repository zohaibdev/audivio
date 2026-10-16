<?php

namespace App\Jobs\Report;

use App\Jobs\Competitor\AnalyzeCompetitorsJob;
use App\Models\Audit;
use App\Models\Report;
use App\Services\Audit\AuditLifecycle;
use App\Services\Audit\AuditSnapshotBuilder;
use App\Services\Report\ReportBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The last stage: build the report, write the immutable snapshot, and only
 * then mark the audit completed — so `completed` means "there is something
 * to look at", not "the numbers exist somewhere".
 */
class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly Audit $audit) {}

    public function handle(
        ReportBuilder $builder,
        AuditSnapshotBuilder $snapshots,
        AuditLifecycle $lifecycle,
    ): void {
        if ($lifecycle->shouldStop($this->audit)) {
            return;
        }

        Report::updateOrCreate(
            ['audit_id' => $this->audit->id],
            ['content' => $builder->build($this->audit), 'generated_at' => now()],
        );

        // Written before the completion transition so a snapshot always
        // exists for every completed audit. build() returns the existing
        // row untouched if this job is retried — a snapshot is written
        // once and never rewritten (§38).
        try {
            $snapshots->build($this->audit->fresh());
        } catch (\Throwable $e) {
            // The report is already saved and is what the user reads; a
            // snapshot failure must not turn a successful audit into a
            // failed one. Logged loudly because it means this audit has no
            // durable provenance record.
            Log::channel('crawler')->error('Audit snapshot could not be written', [
                'audit_id' => $this->audit->id,
                'error' => $e->getMessage(),
            ]);
        }

        $lifecycle->markCompleted($this->audit);

        // After completion, not before: competitor work crawls third-party
        // sites and is the slowest, most failure-prone part of the product
        // (section 42). Running it inside the audit's lifecycle would let an
        // unreachable competitor turn a finished audit of the customer's own
        // site into a failed one.
        AnalyzeCompetitorsJob::dispatch($this->audit->fresh());
    }

    public function failed(?\Throwable $e): void
    {
        app(AuditLifecycle::class)->markFailed(
            $this->audit,
            $e?->getMessage() ?? 'Report generation failed without reporting a reason.',
            'generating_report',
        );
    }
}
