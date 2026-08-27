<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlanAiModelsRequest extends FormRequest
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
        return [
            'ai_model_ids' => ['required', 'array'],
            'ai_model_ids.*' => ['integer', 'distinct', 'exists:ai_models,id'],
        ];
    }
}
