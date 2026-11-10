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
}
