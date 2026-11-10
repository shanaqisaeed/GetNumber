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
            return back()->withErrors(['email' => __('controllers.user.identities.email_disabled')]);
        }

        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'email_password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'current_password' => [$request->user()->password ? 'required' : 'nullable', 'string'],
        ], [
            'email.required' => __('controllers.user.identities.val_email_required'),
            'email.email' => __('controllers.user.identities.val_email_invalid'),
            'email_password.min' => __('controllers.user.identities.val_email_pass_min'),
            'email_password.confirmed' => __('controllers.user.identities.val_email_pass_confirmed'),
            'current_password.required' => __('controllers.user.identities.val_current_pass_required'),
        ]);

        try {
            $this->identityService->linkEmail(
                $request->user(),
                $request->input('email'),
                $request->input('current_password'),
                $request->input('email_password')
            );

            return back()->with('success', __('controllers.user.identities.email_linked'));
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
            return back()->withErrors(['provider' => __('controllers.user.identities.password_provider_restricted')]);
        }

        $user = $request->user();
        $identity = $user->getIdentity($provider);

        // If identity doesn't exist yet for username, auto-create
        if (!$identity && $provider === 'username' && !empty($user->username)) {
            $identity = $user->linkIdentity('username', strtolower($user->username), $user->email);
        }

        if (!$identity) {
            return back()->withErrors(['provider' => __('controllers.user.identities.provider_not_active')]);
        }

        $needsCurrentPassword = !empty($identity->password) || !empty($user->password);

        $request->validate([
            'current_password' => [$needsCurrentPassword ? 'required' : 'nullable', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'current_password.required' => __('controllers.user.identities.val_pass_current_required'),
            'password.required' => __('controllers.user.identities.val_pass_new_required'),
            'password.min' => __('controllers.user.identities.val_pass_new_min'),
            'password.confirmed' => __('controllers.user.identities.val_pass_new_confirmed'),
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
                    'current_password' => __('controllers.user.identities.val_pass_current_incorrect'),
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

        return back()->with('success', __('controllers.user.identities.password_updated', ['provider' => $identity->providerLabel()]));
    }

    /**
     * Link an LDAP account to the user account.
     */
    public function linkLdap(Request $request): RedirectResponse
    {
        if (!SystemSetting::isAuthProviderEnabled('ldap')) {
            return back()->withErrors(['ldap_username' => __('controllers.user.identities.ldap_disabled')]);
        }

        $request->validate([
            'ldap_username' => ['required', 'string'],
            'ldap_password' => ['required', 'string'],
        ], [
            'ldap_username.required' => __('controllers.user.identities.val_ldap_user_required'),
            'ldap_password.required' => __('controllers.user.identities.val_ldap_pass_required'),
        ]);

        try {
            $this->identityService->linkLdap(
                $request->user(),
                $request->input('ldap_username'),
                $request->input('ldap_password')
            );

            return back()->with('success', __('controllers.user.identities.ldap_linked'));
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

            return back()->with('success', __('controllers.user.identities.provider_unlinked', ['provider' => $provider]));
        } catch (ValidationException $e) {
            return back()->withErrors($e->validator->errors());
        }
    }
}

