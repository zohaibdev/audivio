<?php

namespace App\Enums;

/**
 * The sixteen accessibility check areas an audit reports on (§43).
 *
 * axe-core has ~100 rules; a reader has patience for about a dozen
 * groupings. Every axe rule maps to exactly one of these, so a report can
 * say "Forms: 4 problems" instead of listing `label`, `form-field-multiple-labels`,
 * `select-name` and `aria-input-field-name` as four unrelated findings.
 *
 * `whyItMatters()` is deliberately human-authored rather than AI-generated
 * or copied from axe's rule text: it says who is affected and how, which
 * is the part a site owner needs in order to care, and it must not drift
 * with a model's mood between audits.
 */
enum AccessibilityCheck: string
{
    case AltText = 'alt_text';
    case Labels = 'labels';
    case Buttons = 'buttons';
    case Links = 'links';
    case Aria = 'aria';
    case HeadingHierarchy = 'heading_hierarchy';
    case Contrast = 'contrast';
    case Keyboard = 'keyboard';
    case Forms = 'forms';
    case Landmarks = 'landmarks';
    case SemanticHtml = 'semantic_html';
    case Tables = 'tables';
    case Iframes = 'iframes';
    case Language = 'language';
    case SkipLinks = 'skip_links';
    case Focus = 'focus';

    /** Anything axe reports that this map does not name. */
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::AltText => 'Alt text',
            self::Labels => 'Labels',
            self::Buttons => 'Buttons',
            self::Links => 'Links',
            self::Aria => 'ARIA',
            self::HeadingHierarchy => 'Heading hierarchy',
            self::Contrast => 'Contrast',
            self::Keyboard => 'Keyboard accessibility',
            self::Forms => 'Forms',
            self::Landmarks => 'Landmarks',
            self::SemanticHtml => 'Semantic HTML',
            self::Tables => 'Tables',
            self::Iframes => 'Iframes',
            self::Language => 'Language',
            self::SkipLinks => 'Skip links',
            self::Focus => 'Focus',
            self::Other => 'Other',
        };
    }

    /**
     * Who this affects and how. Written for a site owner, not a
     * specialist — "a screen reader announces nothing" lands where
     * "violates 1.1.1" does not.
     */
    public function whyItMatters(): string
    {
        return match ($this) {
            self::AltText => 'Screen readers announce images by their alt text. Without it, a blind visitor hears a filename or nothing at all, and any information carried only by the image is lost to them.',
            self::Labels => 'A form control with no label is announced only by its type — "edit text" — so a screen reader user has no way to know what to type into it.',
            self::Buttons => 'A button with no accessible name is announced as just "button". Someone navigating by voice or screen reader cannot tell what it does, or refer to it by name to activate it.',
            self::Links => 'Links are how screen reader users navigate. A link with no text, or with text like "click here" repeated across a page, gives no basis for choosing between them out of context.',
            self::Aria => 'ARIA overrides what the browser would otherwise tell assistive technology. Used incorrectly it actively misinforms — announcing a control as something it is not is worse than adding nothing.',
            self::HeadingHierarchy => 'Screen reader users navigate long pages by jumping between headings. Skipped levels or missing headings turn that into scrolling through everything.',
            self::Contrast => 'Text that does not contrast enough with its background is unreadable for people with low vision, and for anyone on a phone in daylight.',
            self::Keyboard => 'Anyone who cannot use a mouse — through motor impairment, or because they use a screen reader — operates the page entirely by keyboard. A control that cannot be reached or activated that way cannot be used at all.',
            self::Forms => 'Forms are where visitors buy, sign up and get in touch. A form that assistive technology cannot describe or complete is a direct loss of the thing the page exists to do.',
            self::Landmarks => 'Landmarks let a screen reader user skip straight to the navigation, the main content or the footer. Without them every visit starts at the top of the page.',
            self::SemanticHtml => 'Assistive technology derives meaning from the elements used. A div styled to look like a button is, to a screen reader, not a button.',
            self::Tables => 'A data table without proper headers is announced as an undifferentiated run of cells, so the relationship between a value and what it describes is lost.',
            self::Iframes => 'An unnamed iframe is announced as just "frame", giving no indication of what it contains or whether it is worth entering.',
            self::Language => 'The page language tells a screen reader which pronunciation rules to use. Without it, content is read in the wrong accent or is unintelligible.',
            self::SkipLinks => 'A skip link lets keyboard users jump past a long navigation to the content. Without one they tab through every menu item on every page.',
            self::Focus => 'A visible focus indicator is how a keyboard user knows where they are on the page. Without it, navigating is guesswork.',
            self::Other => 'This finding affects how assistive technology interprets the page.',
        };
    }

    /**
     * axe rule id => check area.
     *
     * Not exhaustive over axe's full rule set on purpose — anything
     * unlisted falls to `Other` and is still reported in full, rather than
     * being dropped because the map has not caught up with an axe upgrade.
     */
    private const RULE_MAP = [
        // Alt text
        'image-alt' => self::AltText,
        'input-image-alt' => self::AltText,
        'area-alt' => self::AltText,
        'object-alt' => self::AltText,
        'role-img-alt' => self::AltText,
        'svg-img-alt' => self::AltText,
        'image-redundant-alt' => self::AltText,

        // Labels
        'label' => self::Labels,
        'label-title-only' => self::Labels,
        'form-field-multiple-labels' => self::Labels,
        'select-name' => self::Labels,
        'aria-input-field-name' => self::Labels,
        'aria-toggle-field-name' => self::Labels,

        // Buttons
        'button-name' => self::Buttons,
        'aria-command-name' => self::Buttons,
        'input-button-name' => self::Buttons,

        // Links
        'link-name' => self::Links,
        'link-in-text-block' => self::Links,
        'identical-links-same-purpose' => self::Links,

        // ARIA
        'aria-allowed-attr' => self::Aria,
        'aria-allowed-role' => self::Aria,
        'aria-required-attr' => self::Aria,
        'aria-required-children' => self::Aria,
        'aria-required-parent' => self::Aria,
        'aria-roles' => self::Aria,
        'aria-valid-attr' => self::Aria,
        'aria-valid-attr-value' => self::Aria,
        'aria-hidden-body' => self::Aria,
        'aria-hidden-focus' => self::Aria,
        'aria-deprecated-role' => self::Aria,
        'aria-text' => self::Aria,
        'aria-treeitem-name' => self::Aria,
        'aria-tooltip-name' => self::Aria,
        'aria-meter-name' => self::Aria,
        'aria-progressbar-name' => self::Aria,
        'aria-dialog-name' => self::Aria,
        'aria-braille-equivalent' => self::Aria,
        'aria-conditional-attr' => self::Aria,
        'aria-prohibited-attr' => self::Aria,

        // Heading hierarchy
        'heading-order' => self::HeadingHierarchy,
        'page-has-heading-one' => self::HeadingHierarchy,
        'empty-heading' => self::HeadingHierarchy,

        // Contrast
        'color-contrast' => self::Contrast,
        'color-contrast-enhanced' => self::Contrast,
        'link-in-text-block-style' => self::Contrast,

        // Keyboard
        'accesskeys' => self::Keyboard,
        'scrollable-region-focusable' => self::Keyboard,
        'nested-interactive' => self::Keyboard,

        // Forms
        'autocomplete-valid' => self::Forms,
        'form-field-multiple-labels-duplicate' => self::Forms,
        'duplicate-id-active' => self::Forms,

        // Landmarks
        'region' => self::Landmarks,
        'landmark-one-main' => self::Landmarks,
        'landmark-unique' => self::Landmarks,
        'landmark-complementary-is-top-level' => self::Landmarks,
        'landmark-banner-is-top-level' => self::Landmarks,
        'landmark-contentinfo-is-top-level' => self::Landmarks,
        'landmark-main-is-top-level' => self::Landmarks,
        'landmark-no-duplicate-banner' => self::Landmarks,
        'landmark-no-duplicate-contentinfo' => self::Landmarks,
        'landmark-no-duplicate-main' => self::Landmarks,

        // Semantic HTML
        'list' => self::SemanticHtml,
        'listitem' => self::SemanticHtml,
        'definition-list' => self::SemanticHtml,
        'dlitem' => self::SemanticHtml,
        'presentation-role-conflict' => self::SemanticHtml,
        'duplicate-id' => self::SemanticHtml,
        'duplicate-id-aria' => self::SemanticHtml,
        'landmark-is-unique' => self::SemanticHtml,
        'document-title' => self::SemanticHtml,
        'marquee' => self::SemanticHtml,
        'blink' => self::SemanticHtml,

        // Tables
        'td-headers-attr' => self::Tables,
        'th-has-data-cells' => self::Tables,
        'table-duplicate-name' => self::Tables,
        'table-fake-caption' => self::Tables,
        'td-has-header' => self::Tables,
        'scope-attr-valid' => self::Tables,
        'empty-table-header' => self::Tables,

        // Iframes
        'frame-title' => self::Iframes,
        'frame-title-unique' => self::Iframes,
        'frame-tested' => self::Iframes,
        'frame-focusable-content' => self::Iframes,

        // Language
        'html-has-lang' => self::Language,
        'html-lang-valid' => self::Language,
        'html-xml-lang-mismatch' => self::Language,
        'valid-lang' => self::Language,

        // Skip links
        'skip-link' => self::SkipLinks,
        'bypass' => self::SkipLinks,

        // Focus
        'focus-order-semantics' => self::Focus,
        'tabindex' => self::Focus,
    ];

    public static function forAxeRule(string $ruleId): self
    {
        return self::RULE_MAP[$ruleId] ?? self::Other;
    }

    /**
     * Every check area a report lists, in display order — including ones
     * with no findings, which is how "passed" is shown at all.
     *
     * `Other` is excluded: it is a fallback bucket, not an area anyone set
     * out to test, so listing it as "passed" would be meaningless.
     *
     * @return self[]
     */
    public static function reportable(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $case) => $case !== self::Other,
        ));
    }
}
