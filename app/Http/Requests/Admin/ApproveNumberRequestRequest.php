<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ApproveNumberRequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->isAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var \App\Models\NumberRequest|null $numberRequest */
        $numberRequest = $this->route('numberRequest');
        $length = $numberRequest?->requested_length;

        $customRules = ['nullable', 'required_if:mode,custom', 'string', 'unique:phone_numbers,number'];

        if ($length) {
            $customRules[] = "digits:{$length}";
        } else {
            $customRules[] = 'regex:/^[0-9]{4,8}$/';
        }

        return [
            'mode' => ['required', 'string', 'in:auto,pool,custom'],
            'custom_number' => $customRules,
            'pool_number_id' => ['nullable', 'required_if:mode,pool', 'integer', 'exists:phone_numbers,id'],
        ];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        /** @var \App\Models\NumberRequest|null $numberRequest */
        $numberRequest = $this->route('numberRequest');
        $length = $numberRequest?->requested_length;

        return [
            'mode.required' => __('requests.admin.approve_number_request.mode_required'),
            'mode.in' => __('requests.admin.approve_number_request.mode_in'),
            'custom_number.required_if' => __('requests.admin.approve_number_request.custom_number_required'),
            'custom_number.unique' => __('requests.admin.approve_number_request.custom_number_unique'),
            'custom_number.digits' => $length
                ? __('requests.admin.approve_number_request.custom_number_digits', ['length' => $length])
                : __('requests.admin.approve_number_request.custom_number_digits_invalid'),
            'custom_number.regex' => __('requests.admin.approve_number_request.custom_number_regex'),
            'pool_number_id.required_if' => __('requests.admin.approve_number_request.pool_number_required'),
            'pool_number_id.exists' => __('requests.admin.approve_number_request.pool_number_exists'),
        ];
    }
}