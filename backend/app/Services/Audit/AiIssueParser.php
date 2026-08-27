<?php

namespace App\Services\Audit;

use App\DTOs\AuditIssueData;
use App\Enums\IssueSeverity;
use Illuminate\Support\Facades\Log;

/**
 * Every AI-driven module prompts for the same response shape (a JSON
 * array of {severity, code, message, recommendation}) and parses it the
 * same defensive way — the model is not guaranteed to follow the format,
 * so a malformed response degrades to "no AI issues" rather than an
 * exception that would take down the whole module.
 */
class AiIssueParser
{
    /**
     * @return AuditIssueData[]
     */
    public static function parse(string $content, string $codePrefix): array
    {
        $json = self::extractJson($content);

        if ($json === null) {
            Log::channel('ai')->warning('AI response was not parseable JSON', ['prefix' => $codePrefix, 'content' => substr($content, 0, 500)]);

            return [];
        }

        $issues = [];

        foreach ($json as $entry) {
            if (! is_array($entry) || empty($entry['message'])) {
                continue;
            }

            $severity = IssueSeverity::tryFrom(strtolower($entry['severity'] ?? '')) ?? IssueSeverity::Medium;
            $code = $entry['code'] ?? 'general';

            $issues[] = new AuditIssueData(
                severity: $severity,
                code: "{$codePrefix}.{$code}",
                message: (string) $entry['message'],
                recommendation: isset($entry['recommendation']) ? (string) $entry['recommendation'] : null,
            );
        }

        return $issues;
    }

    private static function extractJson(string $content): ?array
    {
        $trimmed = trim($content);

        // Strip a ```json ... ``` or ``` ... ``` fence if the model wrapped it.
        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```[a-zA-Z]*\n?/', '', $trimmed);
            $trimmed = preg_replace('/```$/', '', trim($trimmed));
            $trimmed = trim($trimmed);
        }

        $decoded = json_decode($trimmed, true);

        return is_array($decoded) ? $decoded : null;
    }
}
