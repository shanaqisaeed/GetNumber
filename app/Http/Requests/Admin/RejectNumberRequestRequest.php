<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class RejectNumberRequestRequest extends FormRequest
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
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'درج دلیل رد درخواست برای اطلاع متقاضی الزامی است.',
            'reason.min' => 'دلیل رد درخواست باید حداقل ۳ کاراکتر باشد.',
            'reason.max' => 'دلیل رد درخواست نباید بیش از ۵۰۰ کاراکتر باشد.',
        ];
    }
}
