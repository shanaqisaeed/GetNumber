<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\Auth\IdentityAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UserIdentityController extends Controller
{
    public function __construct(
        protected IdentityAuthService $identityService
    ) {}

    /**
     * Link an email address to the user account with an optional dedicated password.
     */
    public function linkEmail(Request $request): RedirectResponse
    {
        if (!SystemSetting::isAuthProviderEnabled('email')) {
            return back()->withErrors(['email' => 'امکان اتصال نشانی ایمیل در تنظیمات عمومی سامانه غیرفعال شده است.']);
        }

        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'email_password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'current_password' => [$request->user()->password ? 'required' : 'nullable', 'string'],
        ], [
            'email.required' => 'ورود نشانی ایمیل الزامی است.',
            'email.email' => 'قالب نشانی ایمیل وارد شده معتبر نمی‌باشد.',
            'email_password.min' => 'کلمه عبور اختصاصی ایمیل باید حداقل ۸ کاراکتر باشد.',
            'email_password.confirmed' => 'تکرار کلمه عبور اختصاصی ایمیل مطابقت ندارد.',
            'current_password.required' => 'ورود گذرواژه فعلی حساب جهت اتصال ایمیل الزامی است.',
        ]);

        try {
            $this->identityService->linkEmail(
                $request->user(),
                $request->input('email'),
                $request->input('current_password'),
                $request->input('email_password')
            );

            return back()->with('success', 'نشانی پست الکترونیک با موفقیت به حساب شما متصل گردید.');
        } catch (ValidationException $e) {
            return back()->withErrors($e->validator->errors())->withInput();
        }
    }

    /**
     * Set or update dedicated password for an identity provider (username, email).
     */
    public function updatePassword(Request $request, string $provider): RedirectResponse
    {
        if (!in_array($provider, ['username', 'email'], true)) {
            return back()->withErrors(['provider' => 'تنظیم گذرواژه اختصاصی فقط برای ورود با نام کاربری و ایمیل مجاز است.']);
        }

        $user = $request->user();
        $identity = $user->getIdentity($provider);

        // If identity doesn't exist yet for username, auto-create
        if (!$identity && $provider === 'username' && !empty($user->username)) {
            $identity = $user->linkIdentity('username', strtolower($user->username), $user->email);
        }

        if (!$identity) {
            return back()->withErrors(['provider' => 'این شیوه ورود در حساب شما فعال نمی‌باشد.']);
        }

        $needsCurrentPassword = !empty($identity->password) || !empty($user->password);

        $request->validate([
            'current_password' => [$needsCurrentPassword ? 'required' : 'nullable', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'current_password.required' => 'ورود کلمه عبور فعلی الزامی است.',
            'password.required' => 'تعیین کلمه عبور جدید الزامی است.',
            'password.min' => 'کلمه عبور جدید باید حداقل ۸ کاراکتر باشد.',
            'password.confirmed' => 'تکرار کلمه عبور جدید با مقدار آن مطابقت ندارد.',
        ]);

        if ($needsCurrentPassword) {
            $verified = false;
            if (!empty($identity->password) && \Illuminate\Support\Facades\Hash::check($request->input('current_password'), $identity->password)) {
                $verified = true;
            } elseif (!empty($user->password) && \Illuminate\Support\Facades\Hash::check($request->input('current_password'), $user->password)) {
                $verified = true;
            }

            if (!$verified) {
                throw ValidationException::withMessages([
                    'current_password' => 'کلمه عبور فعلی وارد شده نادرست است.',
                ]);
            }
        }

        $newHashedPassword = \Illuminate\Support\Facades\Hash::make($request->input('password'));

        $identity->update([
            'password' => $newHashedPassword,
        ]);

        if ($provider === 'username') {
            $user->update([
                'password' => $newHashedPassword,
            ]);
        }

        return back()->with('success', "کلمه عبور ورود برای «{$identity->providerLabel()}» با موفقیت ثبت گردید.");
    }

    /**
     * Link an LDAP account to the user account.
     */
    public function linkLdap(Request $request): RedirectResponse
    {
        if (!SystemSetting::isAuthProviderEnabled('ldap')) {
            return back()->withErrors(['ldap_username' => 'امکان اتصال حساب سازمانی LDAP در تنظیمات عمومی سامانه غیرفعال شده است.']);
        }

        $request->validate([
            'ldap_username' => ['required', 'string'],
            'ldap_password' => ['required', 'string'],
        ], [
            'ldap_username.required' => 'ورود نام کاربری سازمانی LDAP الزامی است.',
            'ldap_password.required' => 'ورود گذرواژه سازمانی LDAP الزامی است.',
        ]);

        try {
            $this->identityService->linkLdap(
                $request->user(),
                $request->input('ldap_username'),
                $request->input('ldap_password')
            );

            return back()->with('success', 'حساب کاربری سازمانی LDAP با موفقیت به حساب شما متصل گردید.');
        } catch (ValidationException $e) {
            return back()->withErrors($e->validator->errors())->withInput();
        }
    }

    /**
     * Unlink an authentication identity provider.
     */
    public function unlink(Request $request, string $provider): RedirectResponse
    {
        try {
            $this->identityService->unlinkProvider($request->user(), $provider);

            return back()->with('success', "اتصال شیوه ورود '{$provider}' با موفقیت از حساب کاربری شما قطع گردید.");
        } catch (ValidationException $e) {
            return back()->withErrors($e->validator->errors());
        }
    }
}

