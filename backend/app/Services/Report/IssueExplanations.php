<?php

namespace App\Services\Report;

use App\Enums\AccessibilityCheck;
use App\Enums\AuditCategory;

/**
 * "Why does this matter?" for every finding (§47).
 *
 * The report already says what is wrong and how to fix it. This is the
 * part in between, and it is the part a non-technical owner actually
 * needs: a missing meta description is not self-evidently a problem, and
 * "add a meta description" is an instruction without a reason.
 *
 * Written per issue code, in the owner's terms and about consequences —
 * what a visitor or a search engine does differently because of this —
 * never restating the rule. An unmapped code falls back to its category's
 * general explanation rather than to silence: a finding with no stated
 * reason invites the reader to dismiss it.
 *
 * Accessibility delegates to `AccessibilityCheck::whyItMatters()` (§43)
 * so those explanations live in exactly one place.
 */
class IssueExplanations
{
    private const BY_CODE = [
        // --- SEO ---
        'seo.missing_title' => 'The title is the headline of your search result and the label on a browser tab. Without one, search engines invent something from the page, and it rarely reads like an invitation to click.',
        'seo.title_length' => 'Titles are cut off in search results past roughly sixty characters, and very short ones waste the most valuable line you get.',
        'seo.missing_meta_description' => 'The description is the sales copy under your search result. Without one, search engines assemble a snippet from the page text, which usually reads like a fragment of a sentence.',
        'seo.meta_description_length' => 'Descriptions are truncated in results, so the end of a long one is never read — and a very short one leaves free space unused.',
        'seo.duplicate_title' => 'Pages sharing a title compete with each other for the same searches, and a search engine picking between them may not pick the one you want.',
        'seo.duplicate_meta_description' => 'A description shared across pages gives search engines nothing to distinguish them by, and gives a searcher no reason to prefer one.',
        'seo.missing_canonical' => 'Without a canonical tag, the same page reached through different URLs — with tracking parameters, with and without a trailing slash — can be treated as several competing pages.',
        'seo.not_indexable' => 'This page is excluded from search results entirely. Everything else about it — its content, its speed, its links — earns nothing while that is true.',
        'seo.client_error_page' => 'Visitors reaching this URL see an error, and search engines drop it from the index. If anything still links here, that traffic is being thrown away.',
        'seo.server_error_page' => 'A server error means the page cannot be delivered at all. Repeated errors also make search engines crawl a site less often.',
        'seo.missing_robots_txt' => 'robots.txt is where you declare your sitemap and steer crawlers away from pages that waste their time. A site works without one, but you give up that control.',
        'seo.missing_sitemap' => 'A sitemap is how a search engine learns about pages that are not reachable within a few clicks of the homepage. Without one, deep pages may simply never be found.',
        'seo.sitemap_not_declared' => 'A sitemap found only by guessing the conventional path is a weaker signal than a declared one, and a crawler that does not guess will never see it.',
        'seo.broken_internal_links' => 'These are links on your own pages sending visitors to dead ends, and sending crawlers to spend their budget on nothing.',
        'seo.redirect_chain' => 'Every hop costs load time on a link you control, and each one dilutes the ranking signal passed to the destination.',
        'seo.redirect_loop' => 'A URL that redirects back to itself is unreachable. Nobody — visitor or search engine — can ever load it.',
        'seo.temporary_redirect' => 'A 302 tells search engines to keep indexing the old URL. If the move is permanent, the new page never inherits the ranking the old one built up.',
        'seo.orphan_pages' => 'A page nothing links to reads as unimportant to a search engine, and is invisible to a visitor browsing the site normally.',
        'seo.missing_h1' => 'The H1 is how both a reader and a search engine work out what a page is about in one glance.',
        'seo.multiple_h1' => 'Several H1s give a page several competing subjects, which makes it less clear what it should rank for.',
        'seo.heading_hierarchy_skip' => 'Heading levels are how screen readers and search engines work out which content belongs to which section. Skipping a level breaks that structure.',
        'seo.missing_image_alt' => 'Alt text is what lets an image rank in image search, and what a screen reader announces in place of the picture.',
        'seo.image_missing_dimensions' => 'Without declared dimensions the browser cannot reserve space, so the page jumps around as images load — which loses clicks and hurts Core Web Vitals.',
        'seo.missing_open_graph' => 'Without Open Graph tags, a link shared on social media or in a chat app renders as a bare URL rather than a card with a title and image.',
        'seo.incomplete_open_graph' => 'A partly-tagged page shares with pieces missing — usually the image, which is the part that gets the click.',
        'seo.missing_twitter_card' => 'Without a card type, X renders your link as plain text no matter what else the page declares.',
        'seo.few_internal_links' => 'Internal links are how a crawler discovers the rest of your site and how it judges which pages matter.',
        'seo.nofollow_internal_links' => 'This tells search engines not to follow a route into your own content, which is almost never what a site intends.',
        'seo.internal_links_to_redirects' => 'These are links on your own pages taking an unnecessary hop. You control both ends, so the cost is avoidable.',

        // --- Performance ---
        'performance.slow_load_time' => 'Visitors leave slow pages. The effect is largest on phones and on the first visit, which is exactly when someone is deciding whether to bother with you.',
        'performance.lcp' => 'This measures how long until the main content appears. Until it does, a visitor is looking at a blank or half-built page and deciding whether to wait.',
        'performance.cls' => 'Content shifting as the page loads makes people tap the wrong thing. It is one of the most reliably infuriating experiences on the web.',
        'performance.pagespeed_score' => 'This is the headline number Google reports for the page, and page experience is one of the inputs to how it ranks.',
        'performance.large_javascript' => 'JavaScript has to be downloaded, parsed and executed before a page becomes usable, so it costs more than its download size suggests — especially on cheaper phones.',
        'performance.large_css' => 'CSS blocks rendering: the browser will not show the page until it has the stylesheet, so every extra kilobyte is time spent looking at nothing.',
        'performance.heavy_images' => 'Images are usually the largest thing on a page and the easiest to shrink without anyone noticing the difference.',
        'performance.no_compression' => 'Compression is typically one server setting and cuts text files by more than half, with no change to the site itself.',
        'performance.no_cache_headers' => 'Without a cache lifetime, returning visitors download files that have not changed, on every single visit.',
        'performance.slow_server_response' => 'Nothing can start until the server answers, so this delay is added to every other timing on the page.',
        'performance.third_party_weight' => 'Third-party scripts run on your page but are not under your control — an outage or a slow response on their side becomes a slow page on yours.',
        'performance.excessive_dom_size' => 'A very large page structure makes every interaction more expensive for the browser to handle, which shows up as sluggishness rather than slow loading.',

        // --- Conversion ---
        'conversion.pages_without_cta' => 'A page a visitor can read but not act on is a dead end. Whatever interest the page created is lost at the bottom of it.',
        'conversion.vague_cta_text' => 'The button label is often the last thing read before a decision. "Submit" tells someone nothing about what they are about to get.',
        'conversion.long_forms' => 'Every extra field costs completions. Fields you could have asked for later cost them for nothing.',
        'conversion.form_friction' => 'Long forms are abandoned. The cost is highest before a visitor is confident they want what is on the other side.',
        'conversion.thin_navigation' => 'Visitors who want more than the page they landed on have nowhere to go, so they leave rather than explore.',
        'conversion.wide_navigation' => 'A menu that has to be read rather than scanned usually results in nothing being picked at all.',
        'conversion.no_contact_route' => 'A site with no visible way to reach anyone reads as one nobody is behind. It is the cheapest trust problem there is to fix.',
        'conversion.no_privacy_policy' => 'Visitors asked for personal details look for a privacy policy before giving them, and in most jurisdictions collecting data without one is a legal problem as well.',
        'conversion.no_social_proof' => 'A first-time visitor has no way to judge you except by what other people say. With none shown, they are asked to take your word for it.',
        'conversion.no_tap_to_call' => 'On a phone, a tap-to-call link turns your highest-intent action into one tap instead of copying digits between apps.',
        'conversion.small_tap_targets' => 'Targets too small to hit reliably cause mis-taps for everyone using a touchscreen, not only people with impairments.',

        // --- Ecommerce ---
        'ecommerce.missing_shipping_information' => 'Unexpected delivery cost at checkout is the single most cited reason people abandon carts. Buyers look for it before they commit.',
        'ecommerce.missing_returns_information' => 'Buyers who cannot find a returns policy before paying often do not pay. It is the reassurance that makes a first purchase feel low-risk.',
        'ecommerce.no_cart_route_found' => 'A cart that only appears after adding an item is invisible to a returning visitor who came back to finish an order.',
        'ecommerce.missing_product_schema' => 'Without product markup, your listings cannot show price, availability or review stars in search results, so they compete against ones that do.',
        'ecommerce.missing_purchase_cta' => 'If the way to buy is not obvious on the page, the visitor who wanted to buy has to go looking for it.',
        'ecommerce.no_review_data' => 'Star ratings in search results are one of the few things that visibly change click-through before a visitor ever reaches your site.',
    ];

    /**
     * Fallbacks by category. Deliberately general but never empty — a
     * finding with no stated reason invites the reader to dismiss it.
     */
    private const BY_CATEGORY = [
        'seo' => 'Search engines use signals like this one to decide what a page is about and whether to show it. Each is small on its own; together they decide whether people can find you at all.',
        'performance' => 'Page speed decides how many visitors stay long enough to see the page, and it is one of the inputs Google uses when ranking.',
        'accessibility' => 'This affects whether people using assistive technology, a keyboard, or a small screen can use the page at all.',
        'conversion' => 'This affects whether a visitor who was interested actually goes on to act, which is what turns traffic into customers.',
        'landing_page' => 'This affects whether a visitor understands what is on offer quickly enough to stay.',
        'ecommerce' => 'This affects whether a shopper can find, trust and complete a purchase without hesitating.',
        'product_auditor' => 'Product pages are where a buying decision is actually made, so gaps here cost sales directly.',
        'aio' => 'AI assistants answer questions by reading pages like this one. Content they cannot parse or attribute is content they will not cite.',
    ];

    public function forIssue(AuditCategory $category, string $code): string
    {
        if (isset(self::BY_CODE[$code])) {
            return self::BY_CODE[$code];
        }

        // Accessibility explanations live with the check definitions (§43)
        // rather than being duplicated here, so the issue list and the
        // accessibility rollup can never disagree about them.
        if (str_starts_with($code, 'axe.')) {
            return AccessibilityCheck::forAxeRule(substr($code, 4))->whyItMatters();
        }

        // Schema findings carry the type as a suffix, and the reason is
        // the same whichever type it is.
        if (str_starts_with($code, 'seo.schema_') || str_starts_with($code, 'seo.missing_schema.')) {
            return 'Structured data is how a search engine reads a page as data rather than prose. Without it, rich results — prices, ratings, breadcrumbs, FAQs — are not available to this page at all.';
        }

        return self::BY_CATEGORY[$category->value] ?? 'This affects how visitors or search engines experience the page.';
    }
}
