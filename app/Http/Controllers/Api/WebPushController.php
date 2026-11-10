<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WebPushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebPushController extends Controller
{
    public function __construct(
        protected WebPushNotificationService $webPushService
    ) {}

    /**
     * Return VAPID Public Key for the client to generate subscription
     */
    public function vapidPublicKey(): JsonResponse
    {
        return response()->json([
            'publicKey' => $this->webPushService->getPublicKey(),
        ]);
    }

    /**
     * Subscribe the authenticated user's current browser
     */
    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => 'required|string',
            'keys' => 'required|array',
            'keys.p256dh' => 'required|string',
            'keys.auth' => 'required|string',
            'content_encoding' => 'nullable|string',
        ]);

        $subscription = $this->webPushService->subscribe($request->user(), $validated);

        return response()->json([
            'success' => true,
            'message' => __('controllers.api.webpush.subscribed'),
            'id' => $subscription->id,
        ]);
    }

    /**
     * Unsubscribe user's current browser
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => 'required|string',
        ]);

        $this->webPushService->unsubscribe($request->user(), $validated['endpoint']);

        return response()->json([
            'success' => true,
            'message' => __('controllers.api.webpush.unsubscribed'),
        ]);
    }

    /**
     * Send a real test push notification to user's registered browsers
     */
    public function testPush(Request $request): JsonResponse
    {
        $result = $this->webPushService->sendTestNotification($request->user());

        return response()->json($result);
    }
}