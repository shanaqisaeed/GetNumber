<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class ValidateServiceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'service_key' => ['required', 'string', 'max:64'],
            'plan_code' => ['nullable', 'string', 'max:64'],
            'order_context' => ['nullable', 'string', 'in:services,enterprise,organizational'],
            'payload' => ['nullable', 'array'],
            'handle' => ['nullable', 'string', 'max:64'],
            'base_domain' => ['nullable', 'string', 'max:64'],
            'tld' => ['nullable', 'string', 'max:24'],
            'preferred_number' => ['nullable', 'string', 'max:24'],
            'allocation_mode' => ['nullable', 'string', 'max:32'],
            'requested_length' => ['nullable', 'integer', 'min:4', 'max:9'],
            'prefix_id' => ['nullable', 'integer'],
            'initial_sub_accounts' => ['nullable'],
            'display_title' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * Normalize payload whether fields were sent inside `payload` or at top level.
     *
     * @return array<string, mixed>
     */
    public function normalizedPayload(): array
    {
        $nested = is_array($this->input('payload')) ? $this->input('payload') : [];
        $topLevel = $this->only([
            'handle',
            'identifier',
            'base_domain',
            'tld',
            'plan_code',
            'preferred_number',
            'allocation_mode',
            'requested_length',
            'prefix_id',
            'initial_sub_accounts',
            'display_title',
            'description',
            'communication_mode',
            'quota_units',
        ]);

        return array_replace($topLevel, $nested);
    }
}

