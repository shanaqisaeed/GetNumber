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

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sub_identifier.required' => __('requests.user.store_sub_resource.sub_identifier_required'),
            'sub_identifier.min' => __('requests.user.store_sub_resource.sub_identifier_min'),
            'sub_identifier.max' => __('requests.user.store_sub_resource.sub_identifier_max'),
            'display_name.max' => __('requests.user.store_sub_resource.display_name_max'),
        ];
    }
}

