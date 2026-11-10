<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\Auth\IdentityAuthService;
use App\Services\Auth\KeycloakAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class KeycloakAuthController extends Controller
{
    public function __construct(
        protected KeycloakAuthService $keycloakService,
        protected IdentityAuthService $identityService
    ) {}

    /**
     * Redirect user to Keycloak SSO authorization screen.
     */
    public function redirect(Request $request): RedirectResponse
    {
        if (!SystemSetting::isAuthProviderEnabled('keycloak')) {
            if (Auth::check()) {
                return redirect()->route('profile.edit')->with('error', __('controllers.auth.keycloak_disabled_profile'));
            }
            return redirect()->route('login')->with('error', __('controllers.auth.keycloak_disabled_login'));
        }

        $action = Auth::check() ? 'link' : 'login';
        $authUrl = $this->keycloakService->getAuthorizationUrl($action);

        return redirect()->away($authUrl);
    }

    /**
     * Handle incoming OAuth2 callback from Keycloak.
     */
    public function callback(Request $request): RedirectResponse
    {
        if (!SystemSetting::isAuthProviderEnabled('keycloak')) {
            return redirect()->route('login')->with('error', __('controllers.auth.keycloak_disabled_general'));
        }

        $code = $request->query('code');
        if (empty($code)) {
            $error = $request->query('error_description') ?: __('controllers.auth.keycloak_rejected');
            return redirect()->route('login')->with('error', $error);
        }

        $stateData = [];
        if ($request->has('state')) {
            try {
                $decoded = json_decode(base64_decode($request->query('state')), true);
                if (is_array($decoded)) {
                    $stateData = $decoded;
                }
            } catch (\Throwable) {
            }
        }

        $profile = $this->keycloakService->handleCallback($code);
        if (!$profile) {
            return redirect()->route('login')->with('error', __('controllers.auth.keycloak_fetch_failed'));
        }

        $action = $stateData['action'] ?? (Auth::check() ? 'link' : 'login');

        // Case A: Link identity to current logged-in user
        if ($action === 'link' && Auth::check()) {
            try {
                $this->identityService->linkKeycloak(Auth::user(), $profile);
                return redirect()->route('profile.edit', ['tab' => 'identities'])
                    ->with('success', __('controllers.auth.keycloak_linked'));
            } catch (ValidationException $e) {
                return redirect()->route('profile.edit', ['tab' => 'identities'])
                    ->with('error', $e->validator->errors()->first());
            }
        }

        // Case B: Authenticate or resolve user
        try {
            $user = $this->identityService->authenticateKeycloak($profile);

            Auth::login($user, true);
            $request->session()->regenerate();

            if ($user->isAdmin()) {
                return redirect()->intended(route('admin.dashboard', absolute: false));
            }

            return redirect()->intended(route('dashboard', absolute: false));
        } catch (ValidationException $e) {
            return redirect()->route('login')->with('error', $e->validator->errors()->first());
        }
    }
}