<?php

namespace App\Jobs\Audit;

use App\Contracts\AuditModule;
use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\AuditScore;
use App\Services\Audit\AuditLifecycle;
use App\Services\Audit\AuditModuleRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Runs one AuditModule against an audit's already-crawled pages, persists
 * its issues + score, then checks whether every module this audit's plan
 * allows has finished — if so, dispatches CalculateScoreJob. Modules are
 * independent, so which finishes last (and thus triggers scoring) is not
 * deterministic.
 */
class RunAuditModuleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /**
     * @param  class-string<AuditModule>  $moduleClass
     */
    public function __construct(
        public readonly Audit $audit,
        public readonly string $moduleClass,
    ) {}

    public function handle(AuditLifecycle $lifecycle): void
    {
        if ($lifecycle->shouldStop($this->audit)) {
            return;
        }

        /** @var AuditModule $module */
        $module = app($this->moduleClass);

        try {
            $result = $module->run($this->audit);
        } catch (\Throwable $e) {
            // Retry before degrading. A module usually fails because
            // something transient went wrong underneath it (an AI provider
            // blip, a lock timeout), and swallowing the first throw made
            // `$tries` dead code — the category was written off on attempt
            // one, permanently, for a fault that a second try would have
            // survived.
            if ($this->hasAttemptsLeft()) {
                throw $e;
            }

            // Out of attempts: degrade rather than fail the audit. One
            // category could not be assessed; the other six still have
            // real findings worth showing. The category is recorded as
            // unassessable rather than left absent — absent would stall
            // the pipeline forever, since
            // dispatchScoreCalculationIfReady() waits for every allowed
            // category to have a score row.
            $this->recordModuleFailure($e);

            return;
        }

        DB::transaction(function () use ($result) {
            AuditIssue::where('audit_id', $this->audit->id)
                ->where('category', $result->category->value)
                ->delete();

            foreach ($result->issues as $issue) {
                $created = AuditIssue::create([
                    'audit_id' => $this->audit->id,
                    'audit_page_id' => $issue->auditPageId,
                    'category' => $result->category,
                    'severity' => $issue->severity,
                    'code' => $issue->code,
                    'message' => Str::limit($issue->message, 500),
                    'recommendation' => $issue->recommendation,
                    'meta' => $issue->meta,
                ]);

                if ($issue->affectedPageIds !== []) {
                    $created->pages()->sync($issue->affectedPageIds);
                }
            }

            AuditScore::updateOrCreate(
                ['audit_id' => $this->audit->id, 'category' => $result->category->value],
                [
                    'score' => $result->score,
                    'is_applicable' => $result->isApplicable,
                    'breakdown' => $result->breakdown,
                    // Persisted since §46. These had been carried on the
                    // result since §34 and then dropped on the floor, so
                    // every skipped service showed the same generic
                    // sentence regardless of why it was actually skipped.
                    'not_applicable_reason' => $result->notApplicableReason,
                    'detection_evidence' => $result->detectionEvidence,
                ],
            );
        });

        $lifecycle->touch($this->audit);

        $this->dispatchScoreCalculationIfReady();
    }

    /**
     * Whether the queue will actually run this job again if it throws now.
     *
     * Two cases where it will not, and rethrowing would just surface the
     * exception with nothing to catch it — losing the graceful degradation
     * instead of retrying:
     *
     * - No queue job behind us at all (a direct `handle()` call).
     * - The `sync` connection, which runs jobs inline and **does not
     *   retry**, whatever `$tries` says. It still reports `attempts() = 1`,
     *   so counting attempts alone is not enough to tell.
     */
    private function hasAttemptsLeft(): bool
    {
        if ($this->job === null || $this->job->getConnectionName() === 'sync') {
            return false;
        }

        return $this->attempts() < $this->tries;
    }

    /**
     * Record the category as unassessable and let the pipeline continue.
     *
     * `score: null, is_applicable: false` is the same shape a genuinely
     * Not Applicable category uses (§34), with `breakdown.error` marking
     * the difference — so nothing downstream can mistake a crashed module
     * for a clean result, and no score is invented to fill the gap.
     */
    private function recordModuleFailure(\Throwable $e): void
    {
        $category = app($this->moduleClass)->category();

        Log::channel('crawler')->error('Audit module failed', [
            'audit_id' => $this->audit->id,
            'module' => $this->moduleClass,
            'error' => $e->getMessage(),
        ]);

        AuditScore::updateOrCreate(
            ['audit_id' => $this->audit->id, 'category' => $category->value],
            [
                'score' => null,
                'is_applicable' => false,
                'breakdown' => ['error' => Str::limit($e->getMessage(), 250)],
            ],
        );

        $this->dispatchScoreCalculationIfReady();
    }

    private function dispatchScoreCalculationIfReady(): void
    {
        $requiredCategories = collect(AuditModuleRegistry::modulesFor($this->audit))
            ->map(fn ($moduleClass) => app($moduleClass)->category()->value);

        $completed = $this->audit->scores()->pluck('category')->map(fn ($c) => $c->value);

        $allDone = $requiredCategories->every(fn ($category) => $completed->contains($category));

        if ($allDone) {
            CalculateScoreJob::dispatch($this->audit);
        }
    }

    /**
     * The queue gave up entirely (both tries exhausted, or the worker
     * died). handle()'s own catch covers a module that throws; this covers
     * the job never getting to run its catch — without it, the missing
     * score row stalls the audit in `analyzing` permanently.
     */
    public function failed(?\Throwable $e): void
    {
        $this->recordModuleFailure(
            $e ?? new \RuntimeException('The analysis job failed without reporting a reason.')
        );
    }
}
