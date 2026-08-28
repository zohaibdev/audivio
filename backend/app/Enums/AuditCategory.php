<?php

namespace App\Enums;

enum AuditCategory: string
{
    case Performance = 'performance';
    case Accessibility = 'accessibility';
    case Seo = 'seo';
    case Ecommerce = 'ecommerce';
    case LandingPage = 'landing_page';
    case Conversion = 'conversion';
    case Aio = 'aio';
    case ProductAuditor = 'product_auditor';
    case Overall = 'overall';
}
