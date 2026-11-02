<?php

namespace App\Services;

use App\Models\Message;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushNotificationService
{
    /**
     * Get the VAPID Public Key for client subscription
     */
    public function getPublicKey(): string
    {
        return config('webpush.vapid.public_key', '');
    }

    /**
     * Store or update a user's browser push subscription
     */
    public function subscribe(User $user, array $data): PushSubscription
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('push_subscriptions')) {
            try {
                \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
            } catch (\Throwable $e) {
                Log::error('[WebPush] Auto-migration error: ' . $e->getMessage());
            }
        }

        $endpoint = $data['endpoint'];
        $endpointHash = PushSubscription::hashEndpoint($endpoint);
        $keys = $data['keys'] ?? [];

        return PushSubscription::updateOrCreate(
            [
                'user_id' => $user->id,
                'endpoint_hash' => $endpointHash,
            ],
            [
                'endpoint' => $endpoint,
                'public_key' => $keys['p256dh'] ?? null,
                'auth_token' => $keys['auth'] ?? null,
                'content_encoding' => $data['content_encoding'] ?? 'aes128gcm',
            ]
        );
    }

    /**
     * Remove a push subscription when user unregisters or revokes permission
     */
    public function unsubscribe(User $user, string $endpoint): bool
    {
        $endpointHash = PushSubscription::hashEndpoint($endpoint);

        return PushSubscription::where('user_id', $user->id)
            ->where('endpoint_hash', $endpointHash)
            ->delete() > 0;
    }

    /**
     * Send Web Push notification for an incoming SMS message.
     * Note: Respects privacy - strictly announces the sender, NEVER the message content!
     */
    public function sendSmsNotification(Message $message): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('push_subscriptions')) {
            return;
        }

        $phoneNumber = $message->phoneNumber;
        if (! $phoneNumber && $message->destination_number) {
            $phoneNumber = \App\Models\PhoneNumber::where('number', $message->destination_number)->first();
        }

        $userId = $phoneNumber?->user_id;

        // If line is assigned to a user, get their subscriptions; otherwise if system line, notify subscribed admins
        if ($userId) {
            $subscriptions = PushSubscription::where('user_id', $userId)->get();
        } else {
            $adminIds = User::where('is_admin', true)->pluck('id');
            $subscriptions = PushSubscription::whereIn('user_id', $adminIds)->get();
        }

        if ($subscriptions->isEmpty()) {
            Log::info("[WebPush] No push subscriptions found for recipient line {$message->destination_number} (userId: " . ($userId ?? 'system') . ")");
            return;
        }

        $publicKey = config('webpush.vapid.public_key');
        $privateKey = config('webpush.vapid.private_key');
        $subject = config('webpush.vapid.subject', 'mailto:support@getnumber.local');

        if (!$publicKey || !$privateKey) {
            Log::warning('[WebPush] VAPID keys not configured. Skipping push notification.');
            return;
        }

        // On Windows environments, ensure OPENSSL_CONF is configured if present
        if (!getenv('OPENSSL_CONF') && file_exists('E:\\php\\extras\\ssl\\openssl.cnf')) {
            putenv('OPENSSL_CONF=E:\\php\\extras\\ssl\\openssl.cnf');
        }

        // Privacy rule: Only state who sent the SMS, never the message body!
        $sender = $message->peer_number ?: $message->sender ?: 'شماره ناشناس';
        $payload = json_encode([
            'title' => 'پیامک جدید دریافت شد',
            'body' => "پیام جدیدی از شماره {$sender} دریافت شد.",
            'icon' => '/favicon.ico',
            'badge' => '/favicon.ico',
            'tag' => 'getnumber-sms-' . $message->id,
            'data' => [
                'url' => '/messages',
                'messageId' => $message->id,
                'sender' => $sender,
            ],
        ], JSON_UNESCAPED_UNICODE);

        try {
            $auth = [
                'VAPID' => [
                    'subject' => $subject,
                    'publicKey' => $publicKey,
                    'privateKey' => $privateKey,
                ],
            ];

            $webPush = new WebPush($auth);

            foreach ($subscriptions as $sub) {
                if (empty($sub->public_key) || empty($sub->auth_token)) {
                    continue;
                }

                $webPushSubscription = Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'publicKey' => $sub->public_key,
                    'authToken' => $sub->auth_token,
                    'contentEncoding' => $sub->content_encoding ?: 'aes128gcm',
                ]);

                $webPush->queueNotification($webPushSubscription, $payload);
            }

            foreach ($webPush->flush() as $report) {
                $endpoint = $report->getRequest()->getUri()->__toString();
                if ($report->isSuccess()) {
                    Log::info("[WebPush] Push delivered successfully to: " . substr($endpoint, 0, 45) . '...');
                } else {
                    Log::warning("[WebPush] Push failed for endpoint: " . substr($endpoint, 0, 45) . "... Reason: " . $report->getReason());
                    if ($report->isSubscriptionExpired()) {
                        PushSubscription::where('endpoint_hash', PushSubscription::hashEndpoint($endpoint))->delete();
                        Log::info("[WebPush] Removed expired subscription: {$endpoint}");
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('[WebPush] Failed sending push notification: ' . $e->getMessage(), [
                'message_id' => $message->id,
                'user_id' => $userId,
            ]);
        }
    }
}

