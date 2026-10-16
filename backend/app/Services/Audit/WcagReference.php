<?php

namespace App\Services\Audit;

/**
 * Turns axe-core's rule tags into a WCAG citation a reader can look up.
 *
 * axe tags a rule with things like `wcag2a`, `wcag143`, `wcag21aa`,
 * `best-practice`, `section508`. The numeric ones encode a success
 * criterion with the dots removed, so `wcag143` is 1.4.3 — which is the
 * only form worth showing a human.
 *
 * A rule tagged only `best-practice` has **no** WCAG criterion, and
 * saying otherwise would be inventing a standards citation for a finding
 * that carries none (§43).
 */
class WcagReference
{
    /**
     * @param  string[]  $tags
     * @return array{criteria: string[], level: string|null, is_best_practice: bool}
     */
    public static function fromAxeTags(array $tags): array
    {
        $criteria = [];
        $level = null;

        foreach ($tags as $tag) {
            if (! is_string($tag)) {
                continue;
            }

            // wcag143 -> 1.4.3
            if (preg_match('/^wcag(\d)(\d)(\d+)$/', $tag, $m)) {
                $criteria[] = "{$m[1]}.{$m[2]}.{$m[3]}";

                continue;
            }

            // wcag2a / wcag21aa -> a conformance level
            if (preg_match('/^wcag\d*(a{1,3})$/', $tag, $m)) {
                $candidate = strtoupper($m[1]);

                // Keep the strictest level seen: a rule tagged both A and
                // AA is reported at AA, since that is what satisfying it
                // actually requires.
                if ($level === null || strlen($candidate) > strlen($level)) {
                    $level = $candidate;
                }
            }
        }

        $criteria = array_values(array_unique($criteria));
        sort($criteria, SORT_NATURAL);

        return [
            'criteria' => $criteria,
            // Only meaningful alongside a criterion. A bare level with
            // nothing to apply it to reads as a conformance claim about
            // the page itself.
            'level' => $criteria === [] ? null : $level,
            'is_best_practice' => $criteria === [] && in_array('best-practice', $tags, true),
        ];
    }

    /**
     * A short citation for display, or null when the rule maps to no
     * criterion at all.
     *
     * @param  array{criteria: string[], level: string|null, is_best_practice: bool}  $reference
     */
    public static function label(array $reference): ?string
    {
        if ($reference['criteria'] === []) {
            return null;
        }

        $criteria = implode(', ', $reference['criteria']);

        return $reference['level'] === null
            ? "WCAG {$criteria}"
            : "WCAG {$criteria} (Level {$reference['level']})";
    }
}
