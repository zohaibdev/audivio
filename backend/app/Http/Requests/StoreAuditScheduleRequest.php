<?php

namespace App\Http\Requests;

use App\Enums\ScheduleFrequency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreAuditScheduleRequest extends FormRequest
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
            'website_id' => ['required', 'integer', 'exists:websites,id'],
            'frequency' => ['required', new Enum(ScheduleFrequency::class)],
        ];
    }
}
