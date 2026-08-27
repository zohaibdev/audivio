<?php

namespace App\Jobs\Audit;

use App\Contracts\AuditModule;
use App\Models\Audit;
use App\Models\AuditIssue;
use App\Models\AuditScore;
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
 * its issues + score, then checks whether every "core" module (Phase 3:
 * performance, accessibility, seo) has finished — if so, dispatches
 * CalculateScoreJob. Modules are independent of each other, so which
 * finishes last (and thus triggers scoring) is not deterministic.
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

    public function handle(): void
    {
        /** @var AuditModule $module */
        $module = app($this->moduleClass);

        try {
            $result = $module->run($this->audit);
        } catch (\Throwable $e) {
            Log::channel('crawler')->error('Audit module failed', [
                'audit_id' => $this->audit->id,
                'module' => $this->moduleClass,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        DB::transaction(function () use ($result) {
            AuditIssue::where('audit_id', $this->audit->id)
                ->where('category', $result->category->value)
                ->delete();

            foreach ($result->issues as $issue) {
                AuditIssue::create([
                    'audit_id' => $this->audit->id,
                    'audit_page_id' => $issue->auditPageId,
                    'category' => $result->category,
                    'severity' => $issue->severity,
                    'code' => $issue->code,
                    'message' => Str::limit($issue->message, 500),
                    'recommendation' => $issue->recommendation,
                    'meta' => $issue->meta,
                ]);
            }

            AuditScore::updateOrCreate(
                ['audit_id' => $this->audit->id, 'category' => $result->category->value],
                ['score' => $result->score, 'breakdown' => $result->breakdown],
            );
        });

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
}
