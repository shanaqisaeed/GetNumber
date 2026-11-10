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
     * Link an email address to the user account.
     */
    public function linkEmail(Request $request): RedirectResponse
    {
        if (!SystemSetting::isAuthProviderEnabled('email')) {
            return back()->withErrors(['email' => 'امکان اتصال نشانی ایمیل در تنظیمات عمومی سامانه غیرفعال شده است.']);
        }

        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'current_password' => [$request->user()->password ? 'required' : 'nullable', 'string'],
        ], [
            'email.required' => 'ورود نشانی ایمیل الزامی است.',
            'email.email' => 'قالب نشانی ایمیل وارد شده معتبر نمی‌باشد.',
            'current_password.required' => 'ورود گذرواژه فعلی حساب جهت اتصال ایمیل الزامی است.',
        ]);

        try {
            $this->identityService->linkEmail(
                $request->user(),
                $request->input('email'),
                $request->input('current_password')
            );

            return back()->with('success', 'نشانی پست الکترونیک با موفقیت به حساب شما متصل گردید.');
        } catch (ValidationException $e) {
            return back()->withErrors($e->validator->errors())->withInput();
        }
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

