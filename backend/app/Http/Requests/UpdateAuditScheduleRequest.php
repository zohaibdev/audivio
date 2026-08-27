<?php

namespace App\Http\Requests;

use App\Enums\ScheduleFrequency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateAuditScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'frequency' => ['sometimes', new Enum(ScheduleFrequency::class)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
