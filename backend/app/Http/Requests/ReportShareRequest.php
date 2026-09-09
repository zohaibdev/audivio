<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating or updating a share (§50).
 *
 * The password is **required on create** — a share without one is a
 * public URL, which is not what this feature is. It is optional on
 * update, where omitting it means "leave the password alone" rather than
 * "remove it".
 */
class ReportShareRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $min = (int) config('report_sharing.min_password_length');
        $isCreate = $this->isMethod('post') && ! $this->routeIs('*.password');

        return [
            'password' => [
                $isCreate ? 'required' : 'sometimes',
                'string',
                "min:{$min}",
                'max:200',
            ],
            'allow_pdf' => ['sometimes', 'boolean'],
            'allow_screenshots' => ['sometimes', 'boolean'],
            'allow_recommendations' => ['sometimes', 'boolean'],
            // Bounded: a share that never expires is a link that outlives
            // whatever reason it was sent for, and one dated years out is
            // the same thing with extra steps.
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now', 'before:'.now()->addYear()->toDateString()],
        ];
    }

    public function messages(): array
    {
        return [
            'password.required' => 'A password is required — a share link without one is a public URL.',
            'expires_at.before' => 'A share link cannot be set to expire more than a year out.',
        ];
    }
}
