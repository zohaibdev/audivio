<?php

namespace Tests\Feature;

use App\Models\AiAnalysis;
use App\Models\Audit;
use App\Models\AuditScore;
use App\Models\Website;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the DB itself rejects a duplicate insert against the unique
 * constraints declared in migrations — application code relies on
 * `updateOrCreate()` at these call sites (RunAuditModuleJob,
 * CalculateScoreJob, AiAnalysisService) rather than ever inserting a
 * true duplicate, so this exercises the constraint directly rather than
 * through those call sites.
 */
class UniqueConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_scores_rejects_a_duplicate_category_for_the_same_audit(): void
    {
        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'status' => 'completed', 'max_pages' => 5]);
        AuditScore::create(['audit_id' => $audit->id, 'category' => 'seo', 'score' => 80]);

        $this->expectException(QueryException::class);

        AuditScore::query()->insert(['audit_id' => $audit->id, 'category' => 'seo', 'score' => 90]);
    }

    public function test_ai_analyses_rejects_a_duplicate_cache_key(): void
    {
        $website = Website::create(['url' => 'https://example.com', 'domain' => 'example.com']);
        $audit = Audit::create(['website_id' => $website->id, 'status' => 'completed', 'max_pages' => 5]);
        AiAnalysis::create(['audit_id' => $audit->id, 'category' => 'aio', 'provider' => 'claude', 'context_hash' => 'abc', 'prompt' => 'x']);

        $this->expectException(QueryException::class);

        AiAnalysis::query()->insert(['audit_id' => $audit->id, 'category' => 'aio', 'provider' => 'claude', 'context_hash' => 'abc', 'prompt' => 'y']);
    }
}
