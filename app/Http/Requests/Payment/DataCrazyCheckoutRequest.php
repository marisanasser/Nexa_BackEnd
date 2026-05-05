<?php

declare(strict_types=1);

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class DataCrazyCheckoutRequest extends FormRequest
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
            'external_event_id' => ['required', 'string', 'max:120'],
            'event_type' => ['nullable', 'string', 'max:80'],
            'external_id' => ['nullable', 'string', 'max:120'],
            'business_id' => ['nullable', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'whatsapp' => ['nullable', 'string', 'max:40'],
            'role' => ['nullable', 'in:creator,brand,student'],
            'plan_code' => ['required', 'string', 'max:80'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:30'],
            'redirect_url' => ['nullable', 'url', 'max:2048'],
            'metadata' => ['nullable', 'array'],
            'metadata.pipeline_stage' => ['nullable', 'string', 'max:120'],
            'metadata.tags' => ['nullable', 'array'],
            'metadata.tags.*' => ['string', 'max:80'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'external_event_id.required' => 'DataCrazy external_event_id is required.',
            'name.required' => 'Lead name is required.',
            'email.required' => 'Lead email is required.',
            'email.email' => 'Lead email is invalid.',
            'plan_code.required' => 'Plan code is required.',
            'plan_code.max' => 'Plan code is too long.',
            'redirect_url.url' => 'Redirect URL is invalid.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        $planCode = $this->input('plan_code');
        $role = $this->input('role');

        $this->merge([
            'email' => is_string($email) ? Str::lower(trim($email)) : $email,
            'plan_code' => is_string($planCode) ? trim($planCode) : $planCode,
            'role' => is_string($role) ? trim($role) : $role,
        ]);
    }
}
