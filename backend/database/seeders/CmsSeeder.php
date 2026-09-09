<?php

namespace Database\Seeders;

use App\Models\CmsPage;
use App\Models\CmsSection;
use Illuminate\Database\Seeder;

/**
 * The CMS content the public site reads.
 *
 * Without this, `cms_pages` is empty on a fresh install: the homepage's
 * `GET /api/cms/homepage` 404s on every load, and the admin CMS opens onto
 * nothing to edit. The frontend falls back to hardcoded copy, so the page
 * still renders — which is exactly why the gap went unnoticed.
 *
 * The values below are those fallbacks, moved into the database verbatim.
 * That is deliberate: seeding must not change what a visitor sees, only
 * make it editable. The fallbacks stay in the frontend as the answer to
 * "what if the CMS row is deleted", which is a different question from
 * "what does this site say".
 */
class CmsSeeder extends Seeder
{
    public function run(): void
    {
        $homepage = CmsPage::updateOrCreate(
            ['slug' => 'homepage'],
            [
                'title' => 'Audivio — AI website audits',
                'meta_description' => 'Audit performance, accessibility, SEO, conversion and AI search readiness in minutes, then fix what matters first.',
            ],
        );

        $this->section($homepage, 'hero', 0, [
            'heading' => 'Know exactly why your website is losing customers.',
            'subheading' => 'Audivio audits performance, accessibility, SEO, conversion, and AI search readiness in minutes — then tells you exactly what to fix first.',
        ]);

        $this->legalPages();
    }

    /**
     * The long-form public pages (§57): privacy, terms, accessibility.
     *
     * Only the `heading` section is seeded — the title, intro and
     * last-updated line, which are what an operator most often needs to
     * change without a deploy (a policy date, a company name).
     *
     * The body prose deliberately lives in the frontend rather than being
     * duplicated here. Legal text kept in two places drifts, and legal
     * text is precisely where drift does damage: you would have a policy
     * in the repository and a different one on the site, with nothing
     * saying which is in force. An admin who does want to override the
     * body can add a `body` section through the CMS; `sectionContent()`
     * prefers it over the shipped default.
     */
    private function legalPages(): void
    {
        $pages = [
            'privacy' => [
                'title' => 'Privacy Policy',
                'meta' => 'What Audivio collects, why, and what we do with it.',
                'intro' => 'This policy describes what Audivio collects, why, and what we do with it. It is written to describe how this product actually works rather than to cover every eventuality in general terms.',
            ],
            'terms' => [
                'title' => 'Terms of Service',
                'meta' => 'What Audivio provides, what it does not claim to provide, and what we each expect.',
                'intro' => 'These terms cover what Audivio provides, what it does not claim to provide, and what we each expect of the other.',
            ],
            'accessibility' => [
                'title' => 'Accessibility Statement',
                'meta' => 'How accessible this site is, and what is still outstanding.',
                'intro' => 'How accessible this site is, what we have done about it, and what we know is still outstanding.',
            ],
        ];

        foreach ($pages as $slug => $page) {
            $model = CmsPage::updateOrCreate(
                ['slug' => $slug],
                ['title' => $page['title'], 'meta_description' => $page['meta']],
            );

            $this->section($model, 'heading', 0, [
                'title' => $page['title'],
                'intro' => $page['intro'],
                'updated' => '5 September 2026',
            ]);
        }
    }

    /**
     * `updateOrCreate` on (page, key), so re-seeding is safe and an admin's
     * edits to a *different* section are never clobbered. Editing a seeded
     * section and re-running the seeder does overwrite it — seeders restore
     * defaults, and that is what restoring means.
     *
     * @param  array<string, mixed>  $content
     */
    private function section(CmsPage $page, string $key, int $order, array $content): void
    {
        CmsSection::updateOrCreate(
            ['cms_page_id' => $page->id, 'section_key' => $key],
            ['content' => $content, 'sort_order' => $order],
        );
    }
}
