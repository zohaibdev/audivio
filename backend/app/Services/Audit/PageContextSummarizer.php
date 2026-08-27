<?php

namespace App\Services\Audit;

use App\Models\Audit;
use App\Models\AuditPage;

/**
 * Builds the compact, pre-extracted context sent to AI providers.
 * Deliberately excludes raw HTML — only the structured fields the
 * crawler already extracted, capped to a configurable page count so
 * prompt size (and token cost) stays bounded regardless of site size.
 */
class PageContextSummarizer
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function summarizePages(Audit $audit): array
    {
        $limit = config('audit_scoring.ai_context_pages');

        return $audit->pages->take($limit)->map(fn (AuditPage $page) => self::summarize($page))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function summarize(AuditPage $page): array
    {
        return [
            'url' => $page->url,
            'title' => $page->title,
            'meta_description' => $page->meta_description,
            'headings' => collect($page->headings ?? [])->take(15)->map(fn ($h) => "H{$h['level']}: {$h['text']}")->all(),
            'cta_texts' => collect($page->cta_elements ?? [])->pluck('text')->take(15)->all(),
            'form_count' => count($page->forms ?? []),
            'form_field_types' => collect($page->forms ?? [])->flatMap(fn ($f) => collect($f['fields'] ?? [])->pluck('type'))->unique()->values()->all(),
            'structured_data_types' => self::structuredDataTypes($page),
            'image_count' => count($page->images ?? []),
            'images_missing_alt' => collect($page->images ?? [])->filter(fn ($i) => empty($i['alt']))->count(),
        ];
    }

    private static function structuredDataTypes(AuditPage $page): array
    {
        return collect($page->structured_data ?? [])
            ->map(fn ($entry) => $entry['@type'] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
