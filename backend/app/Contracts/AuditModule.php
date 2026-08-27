<?php

namespace App\Contracts;

use App\DTOs\AuditModuleResult;
use App\Enums\AuditCategory;
use App\Models\Audit;

/**
 * Each audit category (performance, accessibility, seo, ...) is an
 * independent module: it reads already-crawled AuditPage data for an
 * audit and returns its own issues + score. Modules must not depend on
 * each other's results — only on the crawler's stored output.
 */
interface AuditModule
{
    public function category(): AuditCategory;

    public function run(Audit $audit): AuditModuleResult;
}
