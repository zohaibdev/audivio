<?php

namespace App\Http\Requests\Admin;

use App\Enums\PlanFeature;
use App\Enums\ScheduleFrequency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by the 'admin' middleware at the route level
    }

    /**
     * Every plan control from §40 is settable here — that is the point of
     * the phase: a tier is defined by data an admin edits, not by a
     * constant somebody has to redeploy.
     *
     * The nullable numeric limits mean **unlimited**, never "unset". A
     * `0` is a real limit of none, so the two must stay distinguishable
     * all the way from this request to the column.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $planId = $this->route('plan')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', Rule::unique('plans', 'slug')->ignore($planId)],
            'price_cents' => ['required', 'integer', 'min:0'],

            // --- Volume ---
            'max_pages' => ['required', 'integer', 'min:1'],
            'max_websites' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'daily_audit_limit' => ['required', 'integer', 'min:1'],
            'monthly_audit_limit' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'concurrent_audit_limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'full_recrawls' => ['sometimes', 'integer', 'min:0'],
            'max_audit_frequency' => ['sometimes', Rule::enum(ScheduleFrequency::class)],

            // --- Report visibility ---
            'report_visibility_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'issues_per_service' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],

            // --- AI budget ---
            'ai_analyses_per_audit' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
            'aio_prompt_limit' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
            // How many custom AIO prompts a subscriber may define —
            // a product feature, separate from the execution budget
            // above, which is cost control (section 42).
            'custom_aio_prompt_limit' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],

            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],

            // Keys are constrained to PlanFeature so a typo cannot be
            // saved as a feature that silently grants nothing — the JSON
            // column used to accept anything at all.
            'features' => ['sometimes', 'array'],
            'features.*' => ['boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach (array_keys((array) $this->input('features', [])) as $key) {
                if (! in_array($key, PlanFeature::keys(), true)) {
                    $validator->errors()->add(
                        "features.{$key}",
                        "Unknown plan feature '{$key}'. Allowed: ".implode(', ', PlanFeature::keys()).'.',
                    );
                }
            }
        });
    }
}
