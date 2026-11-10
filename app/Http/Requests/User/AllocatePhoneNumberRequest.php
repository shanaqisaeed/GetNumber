<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class AllocatePhoneNumberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isActive() ?? false;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone_prefix_id' => ['nullable', 'integer', 'exists:phone_prefixes,id'],
            'preferred_number' => ['nullable', 'string', 'regex:/^(\+?[0-9]{1,4})?[0-9]{9}$/'],
            'display_title' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone_prefix_id.exists' => __('requests.user.allocate_phone_number.phone_prefix_id_exists'),
            'preferred_number.regex' => __('requests.user.allocate_phone_number.preferred_number_regex'),
            'display_title.max' => __('requests.user.allocate_phone_number.display_title_max'),
        ];
    }
}