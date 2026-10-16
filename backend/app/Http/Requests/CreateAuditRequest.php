<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Trim the submitted URL before validation so a copy-pasted value with
     * surrounding whitespace isn't rejected as malformed. Nothing else is
     * rewritten here — normalization is UrlNormalizer's job, and it runs in
     * the controller where a rejection can be reported as a 422 with a
     * reason rather than as a silent transformation.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('url'))) {
            $this->merge(['url' => trim($this->input('url'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `url:http,https` rather than a bare `url`: the unqualified
            // rule accepts any scheme PHP recognizes, so `ftp://`,
            // `mailto:` and friends would reach the controller and be
            // rejected there instead of here. This is a first filter only —
            // UrlSecurityValidator re-checks the scheme regardless, since
            // nothing downstream may assume request validation ran.
            'url' => ['required', 'string', 'url:http,https', 'max:'.config('crawler.max_url_length')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'url.url' => 'Enter a full website address starting with http:// or https://.',
        ];
    }
}
