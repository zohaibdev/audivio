<?php

namespace App\Jobs\Audit;

use App\Enums\AuditCategory;
use App\Jobs\Report\GenerateReportJob;
use App\Models\Audit;
use App\Models\AuditScore;
use App\Services\Audit\AuditLifecycle;
use App\Services\Audit\ScoreCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CalculateScoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly Audit $audit) {}

    public function handle(ScoreCalculator $calculator, AuditLifecycle $lifecycle): void
    {
        if ($lifecycle->shouldStop($this->audit)) {
            return;
        }

        $categoryScores = $this->audit->scores()
            ->where('category', '!=', AuditCategory::Overall->value)
            ->get();

        $overall = $calculator->calculateOverall($categoryScores);

        AuditScore::updateOrCreate(
            ['audit_id' => $this->audit->id, 'category' => AuditCategory::Overall->value],
            ['score' => $overall, 'breakdown' => $categoryScores->mapWithKeys(fn ($s) => [$s->category->value => $s->score])->all()],
        );

        // Scored, but not finished: the report and the immutable snapshot
        // still have to be written. Reporting `completed` here — as this
        // job used to — told the user the audit was done while the thing
        // they were about to open did not exist yet.
        $lifecycle->markGeneratingReport($this->audit);

        GenerateReportJob::dispatch($this->audit);
    }

    public function failed(?\Throwable $e): void
    {
        app(AuditLifecycle::class)->markFailed(
            $this->audit,
            $e?->getMessage() ?? 'Scoring failed without reporting a reason.',
            'analyzing',
        );
    }
}
