<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocaleMiddleware
{
    /**
     * Handle an incoming request and set application locale and direction.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supported = ['fa', 'en'];

        // 1. Session locale
        $locale = $request->session()->get('locale');

        // 2. Cookie if session empty
        if (! $locale || ! in_array($locale, $supported, true)) {
            $locale = $request->cookie('app_locale');
        }

        // 3. Fallback to system setting
        if (! $locale || ! in_array($locale, $supported, true)) {
            $locale = SystemSetting::get('default_locale', 'fa');
        }

        if (! in_array($locale, $supported, true)) {
            $locale = 'fa';
        }

        app()->setLocale($locale);

        return $next($request);
    }
}