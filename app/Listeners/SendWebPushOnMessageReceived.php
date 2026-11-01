<?php

namespace App\Listeners;

use App\Enums\MessageDirection;
use App\Events\MessageReceived;
use App\Services\WebPushNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendWebPushOnMessageReceived implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        protected WebPushNotificationService $webPushService
    ) {}

    /**
     * Handle the event.
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

