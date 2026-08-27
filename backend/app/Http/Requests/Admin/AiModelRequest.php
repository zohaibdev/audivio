<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiModelRequest extends FormRequest
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
        $aiModelId = $this->route('aiModel')?->id;

        return [
            'api_provider_id' => [
                $this->isMethod('post') ? 'required' : 'sometimes',
                'integer',
                Rule::exists('api_providers', 'id')->where('provider_type', 'ai'),
            ],
            'model_id' => [
                $this->isMethod('post') ? 'required' : 'sometimes',
                'string',
                'max:255',
                Rule::unique('ai_models', 'model_id')
                    ->where('api_provider_id', $this->input('api_provider_id'))
                    ->ignore($aiModelId),
            ],
            'display_name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_enabled' => ['sometimes', 'boolean'],
            'input_cost_per_1k_tokens' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'output_cost_per_1k_tokens' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'context_window' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'capabilities' => ['sometimes', 'nullable', 'array'],
            'capabilities.*' => ['string'],
        ];
    }
}
