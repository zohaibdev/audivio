<?php

/*
|--------------------------------------------------------------------------
| CRO and ecommerce signals (§46)
|--------------------------------------------------------------------------
| The wording and thresholds behind the deterministic conversion checks.
|
| These sit alongside the AI analysis rather than replacing it: anything
| countable — how many form fields, whether a returns policy is linked,
| whether the CTA says "Submit" — is answered here, deterministically and
| for free. The AI is left the judgement calls it is actually needed for.
*/

return [
    /*
    | Vague CTA wording. A button saying "Submit" tells a visitor nothing
    | about what happens next; one saying "Get my free quote" does.
    */
    'vague_cta_text' => [
        'submit', 'click here', 'here', 'go', 'ok', 'send', 'continue',
        'next', 'more', 'read more', 'learn more', 'find out more',
    ],

    /*
    | Pages whose presence is itself the signal. Matched against both the
    | URL path and the link text, because plenty of sites link a returns
    | policy from the footer without a matching URL.
    */
    'policy_pages' => [
        'privacy' => ['patterns' => ['privacy'], 'label' => 'Privacy policy'],
        'terms' => ['patterns' => ['terms', 'conditions', 'tos'], 'label' => 'Terms and conditions'],
        'contact' => ['patterns' => ['contact', 'get-in-touch'], 'label' => 'Contact page'],
        'about' => ['patterns' => ['about', 'who-we-are', 'our-story'], 'label' => 'About page'],
        'shipping' => ['patterns' => ['shipping', 'delivery', 'postage'], 'label' => 'Shipping information'],
        'returns' => ['patterns' => ['returns', 'refund', 'exchange'], 'label' => 'Returns policy'],
        'faq' => ['patterns' => ['faq', 'help', 'support'], 'label' => 'FAQ or help'],
    ],

    /*
    | Wording that indicates social proof on the page. Deliberately
    | conservative: these are phrases a site uses when it is showing
    | testimonials, not words that happen to appear in prose.
    */
    'social_proof_phrases' => [
        'testimonial', 'what our customers say', 'what our clients say',
        'trusted by', 'as seen in', 'case study', 'success story',
        'customer reviews', 'rated', 'star rating',
    ],

    /*
    | Trust wording — guarantees and reassurance near the point of action.
    */
    'trust_phrases' => [
        'money back', 'money-back', 'guarantee', 'secure checkout',
        'ssl', 'free returns', 'no obligation', 'cancel anytime',
        'gdpr', 'accredited', 'certified',
    ],

    'checkout_phrases' => [
        'guest checkout', 'checkout as guest', 'secure checkout',
        'express checkout', 'apple pay', 'google pay', 'paypal',
    ],

    /*
    | Thresholds. Each is the point at which something stops being normal
    | and starts being worth telling an owner about.
    */
    'thresholds' => [
        // Forms longer than this ask for more than most visitors will give
        // before they know what they are getting.
        'max_form_fields' => 7,
        // A page with no way forward at all.
        'min_ctas_per_page' => 1,
        // Navigation wide enough that visitors have to read rather than
        // scan it.
        'max_primary_nav_links' => 12,
        // Below this, a site's navigation is too thin to find anything.
        'min_primary_nav_links' => 2,
    ],
];
