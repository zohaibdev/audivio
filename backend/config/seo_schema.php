<?php

/*
|--------------------------------------------------------------------------
| Structured data types the audit reports on (§44)
|--------------------------------------------------------------------------
| For each schema.org type: the properties Google documents as required
| for the corresponding rich result, and the ones it lists as recommended.
|
| Missing *required* properties make a rich result ineligible — a concrete,
| checkable fact. Missing *recommended* ones only weaken it, and are
| reported at a lower severity accordingly. Nothing here is invented: a
| property that Google does not document as required is not treated as
| required, however useful it might seem.
|
| `aliases` covers the subtypes that inherit a type's requirements, so a
| `Restaurant` is audited as the LocalBusiness it is rather than falling
| through to the generic bucket.
*/

return [
    'types' => [
        'Organization' => [
            'label' => 'Organization',
            'why' => 'Tells search engines who publishes this site, and feeds the knowledge panel that appears beside branded searches.',
            'required' => ['name'],
            'recommended' => ['url', 'logo', 'sameAs'],
            'aliases' => ['Corporation', 'NGO', 'EducationalOrganization', 'GovernmentOrganization', 'OnlineBusiness'],
        ],

        'LocalBusiness' => [
            'label' => 'Local business',
            'why' => 'Drives the local pack and map results. Without an address and opening hours, a business is much harder to surface for "near me" searches.',
            'required' => ['name', 'address'],
            'recommended' => ['telephone', 'openingHours', 'geo', 'priceRange', 'url'],
            'aliases' => ['Restaurant', 'Store', 'Dentist', 'MedicalBusiness', 'ProfessionalService', 'AutomotiveBusiness', 'FoodEstablishment', 'HomeAndConstructionBusiness', 'LodgingBusiness', 'HealthAndBeautyBusiness', 'FinancialService', 'LegalService', 'RealEstateAgent'],
        ],

        'Product' => [
            'label' => 'Product',
            'why' => 'Required for price, availability and review stars to appear in search results.',
            'required' => ['name', 'offers'],
            'recommended' => ['image', 'description', 'brand', 'sku', 'aggregateRating', 'review'],
            'aliases' => ['ProductModel', 'IndividualProduct', 'ProductGroup'],
        ],

        'Article' => [
            'label' => 'Article',
            'why' => 'Lets search engines show a headline, publication date and author for editorial content.',
            'required' => ['headline'],
            'recommended' => ['image', 'datePublished', 'dateModified', 'author'],
            'aliases' => ['NewsArticle', 'BlogPosting', 'TechArticle', 'ScholarlyArticle', 'Report'],
        ],

        'BreadcrumbList' => [
            'label' => 'Breadcrumb',
            'why' => 'Replaces the raw URL under a search result with a readable path, which is a real click-through difference.',
            'required' => ['itemListElement'],
            'recommended' => [],
            'aliases' => [],
        ],

        'FAQPage' => [
            'label' => 'FAQ',
            'why' => 'Can expand a result with questions and answers directly in the search listing.',
            'required' => ['mainEntity'],
            'recommended' => [],
            'aliases' => ['QAPage'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Site-wide expectations
    |--------------------------------------------------------------------------
    | Types that should appear somewhere on the site rather than on every
    | page. Absence is a finding; absence on any individual page is not.
    */
    'site_wide_types' => ['Organization'],

    /*
    |--------------------------------------------------------------------------
    | Evidence limits
    |--------------------------------------------------------------------------
    */
    'max_pages_listed_per_issue' => 10,
];
