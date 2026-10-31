<?php

namespace App\Http\Controllers\User;

use App\Contracts\WalletServiceInterface;
use App\Enums\WalletTransactionType;
use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WalletController extends Controller
{
    public function __construct(
        protected WalletServiceInterface $walletService
    ) {}

    /**
     * Display user wallet overview (redirects to Profile wallet & transaction section).
     */
    public function index(Request $request): \Illuminate\Http\RedirectResponse
    {
        return redirect()->route('profile.edit', ['tab' => 'wallet', 'view' => 'transactions']);
    }
}

