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
            'mode.required' => 'تعیین شیوه تخصیص سرشماره الزامی است.',
            'mode.in' => 'شیوه تخصیص انتخاب‌شده نامعتبر است.',
            'custom_number.required_if' => 'در حالت تخصیص دستی، درج مقدار سرشماره الزامی است.',
            'custom_number.unique' => 'این شماره قبلاً در سامانه ثبت شده و در دسترس نمی‌باشد.',
            'custom_number.digits' => $length ? "طول سرشماره دستی باید دقیقاً {$length} رقم باشد." : 'طول شماره دستی نامعتبر است.',
            'custom_number.regex' => 'سرشماره دستی باید فقط شامل ارقام عددی بین ۴ تا ۸ رقم باشد.',
            'pool_number_id.required_if' => 'در حالت انتخاب از استخر، انتخاب خط الزامی است.',
            'pool_number_id.exists' => 'خط انتخاب‌شده در استخر شماره‌های سامانه معتبر نمی‌باشد.',
        ];
    }
}
