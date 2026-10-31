<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'role' => $user->role->value,
                    'status' => $user->status->value,
                    'is_admin' => $user->isAdmin(),
                ] : null,
                'unread_messages_count' => fn () => $user ? \App\Models\Message::whereIn('phone_number_id', $user->phoneNumbers()->pluck('id'))
                    ->where('direction', \App\Enums\MessageDirection::Inbound)
                    ->where('processing_status', \App\Enums\MessageProcessingStatus::Received)
                    ->count() : 0,
                'wallet_balance' => fn () => $user ? (string) $user->getOrCreateWallet()->balance : '0.0000',
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
