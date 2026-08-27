<?php

namespace App\Jobs\Audit;

use App\Enums\AuditCategory;
use App\Enums\AuditStatus;
use App\Models\Audit;
use App\Models\AuditScore;
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

    public function handle(ScoreCalculator $calculator): void
    {
        $categoryScores = $this->audit->scores()
            ->where('category', '!=', AuditCategory::Overall->value)
            ->get();

        $overall = $calculator->calculateOverall($categoryScores);

        AuditScore::updateOrCreate(
            ['audit_id' => $this->audit->id, 'category' => AuditCategory::Overall->value],
            ['score' => $overall, 'breakdown' => $categoryScores->mapWithKeys(fn ($s) => [$s->category->value => $s->score])->all()],
        );

        $this->audit->update(['status' => AuditStatus::Completed, 'completed_at' => now()]);
    }
}
