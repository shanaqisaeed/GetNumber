<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view with active authentication providers.
     */
    public function create(): Response
    {
        $enabledProviders = SystemSetting::getEnabledAuthProviders();

        return Inertia::render('Auth/Login', [
            'status' => session('status'),
            'enabledProviders' => $enabledProviders,
            'authMethods' => [
                'username' => in_array('username', $enabledProviders),
                'email' => in_array('email', $enabledProviders),
                'ldap' => in_array('ldap', $enabledProviders),
                'keycloak' => in_array('keycloak', $enabledProviders),
            ],
            'keycloakLoginUrl' => in_array('keycloak', $enabledProviders)
                ? route('auth.keycloak.redirect')
                : null,
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        if ($request->session()->has('login.id')) {
            return redirect()->route('two-factor.challenge');
        }

        $request->session()->regenerate();

        if ($request->user()->isAdmin()) {
            return redirect()->intended(route('admin.dashboard', absolute: false));
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
