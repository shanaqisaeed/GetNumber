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
            'requested_length' => ['required', 'integer', 'between:4,8'],
            'service_title' => ['required', 'string', 'min:2', 'max:100'],
            'description' => ['required', 'string', 'min:5', 'max:1000'],
            'preferred_number' => ['nullable', 'string', 'regex:/^[0-9]{4,8}$/'],
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
            'requested_length.required' => 'تعیین طول سرشماره سازمانی الزامی است.',
            'requested_length.between' => 'طول سرشماره درخواستی باید بین ۴ تا ۸ رقم باشد.',
            'service_title.required' => 'درج عنوان سرویس الزامی است.',
            'service_title.min' => 'عنوان سرویس باید حداقل ۲ کاراکتر باشد.',
            'service_title.max' => 'عنوان سرویس نباید بیش از ۱۰۰ کاراکتر باشد.',
            'description.required' => 'درج توضیح کاربرد سرشماره سازمانی الزامی است.',
            'description.min' => 'توضیح کاربرد باید حداقل ۵ کاراکتر باشد.',
            'description.max' => 'توضیح کاربرد نباید بیش از ۱۰۰۰ کاراکتر باشد.',
            'preferred_number.regex' => 'سرشماره پیشنهادی باید فقط شامل ارقام عددی بین ۴ تا ۸ رقم باشد.',
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
                        "طول سرشماره پیشنهادی ({strlen($cleaned)} رقم) با طول درخواستی انتخاب‌شده ({$length} رقم) همخوانی ندارد."
                    );
                }
            }
        });
    }
}
