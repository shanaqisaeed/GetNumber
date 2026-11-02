<?php

namespace App\Listeners;

use App\Enums\MessageDirection;
use App\Events\MessageReceived;
use App\Services\WebPushNotificationService;
use Illuminate\Support\Facades\Log;

class SendWebPushOnMessageReceived
{
    public function __construct(
        protected WebPushNotificationService $webPushService
    ) {}

    /**
     * Handle the event synchronously so notifications are dispatched immediately without depending on queue workers.
     */
    public function handle(MessageReceived $event): void
    {
        $message = $event->message;

        // Only send push notifications for inbound SMS messages
        if ($message->direction !== MessageDirection::Inbound) {
            return;
        }

        try {
            $this->webPushService->sendSmsNotification($message);
        } catch (\Throwable $e) {
            Log::error('[WebPush] Error handling MessageReceived event: ' . $e->getMessage());
        }
    }
}

