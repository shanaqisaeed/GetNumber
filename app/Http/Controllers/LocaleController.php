<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    /**
     * Switch application language and redirect back.
     */
    public function update(Request $request, string $locale): RedirectResponse
    {
        if (in_array($locale, ['fa', 'en'], true)) {
            $request->session()->put('locale', $locale);
            cookie()->queue('app_locale', $locale, 60 * 24 * 365);
        }

        return back();
    }
}