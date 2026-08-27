<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by the 'admin' middleware at the route level
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $planId = $this->route('plan')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', Rule::unique('plans', 'slug')->ignore($planId)],
            'price_cents' => ['required', 'integer', 'min:0'],
            'max_pages' => ['required', 'integer', 'min:1'],
            'daily_audit_limit' => ['required', 'integer', 'min:1'],
            'full_recrawls' => ['sometimes', 'integer', 'min:0'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'features' => ['sometimes', 'array'],
        ];
    }
}
