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
            // Custom number and title are optional and currently handled automatically by allocation service
            'preferred_number' => ['nullable', 'string', 'regex:/^(\+?85)?[0-9]{9}$/'],
            'display_title' => ['nullable', 'string', 'max:100'],
        ];
    }
}
