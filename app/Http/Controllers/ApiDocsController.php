<?php

namespace App\Http\Controllers;

use App\Models\PhonePrefix;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApiDocsController extends Controller
{
    /**
     * Display the public RESTful API documentation.
     * Open to all users without authentication.
     */
    public function index(Request $request): Response
    {
        $defaultPrefix = PhonePrefix::getDefaultPrefixString();
        $activePrefixes = PhonePrefix::getActiveCleanPrefixes();

        $baseUrl = config('app.url') ? rtrim(config('app.url'), '/') : url('/');

        return Inertia::render('Docs/Api', [
            'baseUrl' => $baseUrl,
            'defaultPrefix' => $defaultPrefix,
            'activeCleanPrefixes' => $activePrefixes,
            'mockToken' => 'gn_live_mock_token_abcdef1234567890',
            'mockDestination' => $defaultPrefix . '000010001',
        ]);
    }
}
