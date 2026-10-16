<?php

namespace App\Services\Plan;

use App\Enums\PlanFeature;
use App\Enums\ScheduleFrequency;
use App\Models\Plan;

/**
 * What one caller — a subscriber or an anonymous visitor — is allowed to
 * do. Immutable, resolved once by EntitlementResolver, and the only thing
 * any gate should read.
 *
 * The point of a value object here rather than passing `?Plan` around: a
 * guest has no plan row, an expired subscriber's plan is not the plan they
 * are entitled to, and "unlimited" has to be expressible. Every caller
 * that reached for `$user->plan()->max_pages` had to get all three right
 * on its own, and they did not — the website cap and monthly cap simply
 * did not exist, and the guest branch was re-derived from config in three
 * different places.
 *
 * **Null means unlimited** in every numeric field. Zero is a real limit of
 * none, and the two are never interchangeable.
 */
readonly class Entitlements
{
    /**
     * @param  array<string, bool>  $features
     */
    public function __construct(
        public ?Plan $plan,
        public int $maxWebsites,
        public int $maxPages,
        public int $dailyAuditLimit,
        public ?int $monthlyAuditLimit,
        public int $concurrentAuditLimit,
        public ScheduleFrequency $maxAuditFrequency,
        public int $reportVisibilityPercent,
        public ?int $issuesPerService,
        public ?int $aiAnalysesPerAudit,
        public ?int $aioPromptLimit,
        public ?int $customAioPromptLimit,
        private array $features,
        public bool $isGuest,
        /** True when the caller's own subscription has lapsed and these are the default plan's terms. */
        public bool $isDowngraded,
    ) {}

    public static function fromPlan(Plan $plan, bool $isDowngraded = false): self
    {
        return new self(
            plan: $plan,
            maxWebsites: (int) $plan->max_websites,
            maxPages: (int) $plan->max_pages,
            dailyAuditLimit: (int) $plan->daily_audit_limit,
            monthlyAuditLimit: $plan->monthly_audit_limit === null ? null : (int) $plan->monthly_audit_limit,
            concurrentAuditLimit: (int) $plan->concurrent_audit_limit,
            maxAuditFrequency: ScheduleFrequency::tryFrom((string) $plan->max_audit_frequency) ?? ScheduleFrequency::Monthly,
            reportVisibilityPercent: (int) $plan->report_visibility_percent,
            issuesPerService: $plan->issues_per_service === null ? null : (int) $plan->issues_per_service,
            aiAnalysesPerAudit: $plan->ai_analyses_per_audit === null ? null : (int) $plan->ai_analyses_per_audit,
            aioPromptLimit: $plan->aio_prompt_limit === null ? null : (int) $plan->aio_prompt_limit,
            customAioPromptLimit: $plan->custom_aio_prompt_limit === null ? null : (int) $plan->custom_aio_prompt_limit,
            features: array_map(fn ($v) => (bool) $v, $plan->features ?? []),
            isGuest: false,
            isDowngraded: $isDowngraded,
        );
    }

    /**
     * @param  array<string, mixed>  $config  config('entitlements.guest')
     */
    public static function fromGuestConfig(array $config): self
    {
        return new self(
            plan: null,
            maxWebsites: (int) $config['max_websites'],
            maxPages: (int) $config['max_pages'],
            dailyAuditLimit: (int) $config['daily_audit_limit'],
            monthlyAuditLimit: $config['monthly_audit_limit'] === null ? null : (int) $config['monthly_audit_limit'],
            concurrentAuditLimit: (int) $config['concurrent_audit_limit'],
            maxAuditFrequency: ScheduleFrequency::tryFrom((string) $config['max_audit_frequency']) ?? ScheduleFrequency::Monthly,
            reportVisibilityPercent: (int) $config['report_visibility_percent'],
            issuesPerService: $config['issues_per_service'] === null ? null : (int) $config['issues_per_service'],
            aiAnalysesPerAudit: $config['ai_analyses_per_audit'] === null ? null : (int) $config['ai_analyses_per_audit'],
            aioPromptLimit: $config['aio_prompt_limit'] === null ? null : (int) $config['aio_prompt_limit'],
            customAioPromptLimit: ($config['custom_aio_prompt_limit'] ?? 0) === null ? null : (int) ($config['custom_aio_prompt_limit'] ?? 0),
            features: array_map(fn ($v) => (bool) $v, $config['features'] ?? []),
            isGuest: true,
            isDowngraded: false,
        );
    }

    /**
     * Whether an on/off entitlement is granted.
     *
     * A feature whose implementation does not exist yet (an MVP exclusion —
     * white label, competitor discovery) reports false however the plan is
     * configured. The flag stays admin-settable so tiers can be priced
     * ahead of the build, but nothing may act as though the capability is
     * there: answering true would make callers render a white-label report
     * that no renderer produces.
     */
    public function allows(PlanFeature $feature): bool
    {
        if (! $feature->isBuilt()) {
            return false;
        }

        return $this->features[$feature->value] ?? false;
    }

    /** What the plan has configured, regardless of whether it is built yet. */
    public function isConfiguredFor(PlanFeature $feature): bool
    {
        return $this->features[$feature->value] ?? false;
    }

    public function hasFullReportAccess(): bool
    {
        return $this->reportVisibilityPercent >= 100;
    }

    /**
     * How many of $total items a restricted viewer may see.
     *
     * Rounded up, so a plan showing "50%" of a single finding shows that
     * finding rather than nothing — a report that proves the audit ran is
     * the entire point of the restricted view.
     */
    public function visibleCount(int $total): int
    {
        if ($this->reportVisibilityPercent >= 100) {
            return $total;
        }

        if ($this->reportVisibilityPercent <= 0 || $total <= 0) {
            return 0;
        }

        return (int) ceil($total * $this->reportVisibilityPercent / 100);
    }

    public function allowsFrequency(ScheduleFrequency $frequency): bool
    {
        return $frequency->intervalDays() >= $this->maxAuditFrequency->intervalDays();
    }

    /**
     * @return array<string, mixed> the shape /api/me/entitlements returns
     */
    public function toArray(): array
    {
        return [
            'plan' => $this->plan === null ? null : [
                'id' => $this->plan->id,
                'name' => $this->plan->name,
                'slug' => $this->plan->slug,
            ],
            'is_guest' => $this->isGuest,
            'is_downgraded' => $this->isDowngraded,
            'limits' => [
                'max_websites' => $this->maxWebsites,
                'max_pages' => $this->maxPages,
                'daily_audit_limit' => $this->dailyAuditLimit,
                'monthly_audit_limit' => $this->monthlyAuditLimit,
                'concurrent_audit_limit' => $this->concurrentAuditLimit,
                'max_audit_frequency' => $this->maxAuditFrequency->value,
                'report_visibility_percent' => $this->reportVisibilityPercent,
                'issues_per_service' => $this->issuesPerService,
                'ai_analyses_per_audit' => $this->aiAnalysesPerAudit,
                'aio_prompt_limit' => $this->aioPromptLimit,
                'custom_aio_prompt_limit' => $this->customAioPromptLimit,
            ],
            'features' => collect(PlanFeature::cases())
                ->mapWithKeys(fn (PlanFeature $f) => [$f->value => [
                    'label' => $f->label(),
                    'enabled' => $this->allows($f),
                    // Distinguishes "your plan does not include this" from
                    // "nobody has this yet", so the UI can say which.
                    'available' => $f->isBuilt(),
                ]])
                ->all(),
        ];
    }
}
