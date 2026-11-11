<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class StoreSubResourceRequest extends FormRequest
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
            'sub_identifier' => ['required', 'string', 'min:2', 'max:48'],
            'display_name' => ['nullable', 'string', 'max:120'],
        ];
    }
}

