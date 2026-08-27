<?php

namespace Tests\Unit\Services\AI;

use App\Services\Audit\AiIssueParser;
use Tests\TestCase;

class AiIssueParserTest extends TestCase
{
    public function test_parses_plain_json_array(): void
    {
        $content = json_encode([
            ['severity' => 'high', 'code' => 'unclear_value_prop', 'message' => 'Headline is vague', 'recommendation' => 'Be specific'],
        ]);

        $issues = AiIssueParser::parse($content, 'ai.landing_page');

        $this->assertCount(1, $issues);
        $this->assertSame('high', $issues[0]->severity->value);
        $this->assertSame('ai.landing_page.unclear_value_prop', $issues[0]->code);
        $this->assertSame('Headline is vague', $issues[0]->message);
    }

    public function test_strips_markdown_code_fence(): void
    {
        $content = "```json\n".json_encode([
            ['severity' => 'medium', 'code' => 'x', 'message' => 'Finding'],
        ])."\n```";

        $issues = AiIssueParser::parse($content, 'ai.seo');

        $this->assertCount(1, $issues);
        $this->assertSame('Finding', $issues[0]->message);
    }

    public function test_invalid_severity_falls_back_to_medium(): void
    {
        $content = json_encode([
            ['severity' => 'not-a-real-severity', 'code' => 'x', 'message' => 'Finding'],
        ]);

        $issues = AiIssueParser::parse($content, 'ai.seo');

        $this->assertSame('medium', $issues[0]->severity->value);
    }

    public function test_malformed_json_returns_empty_array(): void
    {
        $issues = AiIssueParser::parse('this is not json at all', 'ai.seo');

        $this->assertSame([], $issues);
    }

    public function test_empty_array_response_returns_empty_array(): void
    {
        $issues = AiIssueParser::parse('[]', 'ai.seo');

        $this->assertSame([], $issues);
    }

    public function test_entries_missing_message_are_skipped(): void
    {
        $content = json_encode([
            ['severity' => 'high', 'code' => 'x'], // no message
            ['severity' => 'high', 'code' => 'y', 'message' => 'Valid finding'],
        ]);

        $issues = AiIssueParser::parse($content, 'ai.seo');

        $this->assertCount(1, $issues);
        $this->assertSame('Valid finding', $issues[0]->message);
    }
}
