<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required',
                'string',
                'min:3',
                'max:50',
                'alpha_dash',
                Rule::unique(User::class, 'username')->ignore($this->user()->id),
            ],
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
            'name.required' => 'ورود عنوان سازمانی / نام الزامی است.',
            'username.required' => 'ورود شناسه کاربری الزامی است.',
            'username.unique' => 'این شناسه کاربری قبلاً در سامانه ثبت شده است.',
            'username.alpha_dash' => 'شناسه کاربری فقط می‌تواند شامل حروف انگلیسی، اعداد، خط تیره و زیرخط باشد.',
        ];
    }
}
