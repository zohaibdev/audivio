<?php

namespace App\Jobs\Report;

use App\Models\Audit;
use App\Models\Report;
use App\Services\Report\ReportBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly Audit $audit) {}

    public function handle(ReportBuilder $builder): void
    {
        Report::updateOrCreate(
            ['audit_id' => $this->audit->id],
            ['content' => $builder->build($this->audit), 'generated_at' => now()],
        );
    }
}
