<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreNumberRequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->isActive();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone_prefix_id' => ['nullable', 'integer', 'exists:phone_prefixes,id'],
            'requested_length' => ['required', 'integer', 'between:4,8'],
            'service_title' => ['required', 'string', 'min:2', 'max:100'],
            'description' => ['required', 'string', 'min:5', 'max:1000'],
            'preferred_number' => ['nullable', 'string', 'regex:/^[0-9]{4,8}$/'],
            'accepts_incoming_sms' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Custom validation messages in formal Persian.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'requested_length.required' => __('requests.user.store_number_request.requested_length_required'),
            'requested_length.between' => __('requests.user.store_number_request.requested_length_between'),
            'service_title.required' => __('requests.user.store_number_request.service_title_required'),
            'service_title.min' => __('requests.user.store_number_request.service_title_min'),
            'service_title.max' => __('requests.user.store_number_request.service_title_max'),
            'description.required' => __('requests.user.store_number_request.description_required'),
            'description.min' => __('requests.user.store_number_request.description_min'),
            'description.max' => __('requests.user.store_number_request.description_max'),
            'preferred_number.regex' => __('requests.user.store_number_request.preferred_number_regex'),
        ];
    }

    /**
     * Additional validation after initial rules pass.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $preferred = $this->input('preferred_number');
            $length = (int) $this->input('requested_length');

            if ($preferred !== null && trim((string) $preferred) !== '') {
                $cleaned = preg_replace('/\D/', '', (string) $preferred);
                if (strlen($cleaned) !== $length) {
                    $validator->errors()->add(
                        'preferred_number',
                        __('requests.user.store_number_request.preferred_number_length_mismatch', [
                            'actual' => strlen($cleaned),
                            'expected' => $length,
                        ])
                    );
                }
            }
        });
    }
}