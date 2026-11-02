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
     * Resolve valid VAPID subject claim (automatically from config/env APP_URL)
     */
    public function resolveSubject(): string
    {
        $subject = config('webpush.vapid.subject');
        if (empty($subject) || str_ends_with($subject, '.local')) {
            $subject = config('app.url');
        }

        if (!str_starts_with($subject, 'http://') && !str_starts_with($subject, 'https://') && !str_starts_with($subject, 'mailto:')) {
            $subject = 'https://' . ltrim($subject, '/');
        }

        return $subject;
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

        // Security & privacy fix: A browser endpoint must belong to only one user account at a time.
        // Purge any stale subscriptions associated with this endpoint from other users.
        PushSubscription::where('endpoint_hash', $endpointHash)
            ->where('user_id', '!=', $user->id)
            ->delete();

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

        // Inbound SMS notification should strictly be delivered to the line owner
        if (!$userId) {
            Log::info("[WebPush] No line owner found for {$message->destination_number}. Skipping push notification.");
            return;
        }

        $subscriptions = PushSubscription::where('user_id', $userId)
            ->get()
            ->unique('endpoint_hash');

        if ($subscriptions->isEmpty()) {
            Log::info("[WebPush] No push subscriptions found for recipient line {$message->destination_number} (userId: {$userId})");
            return;
        }

        $publicKey = config('webpush.vapid.public_key');
        $privateKey = config('webpush.vapid.private_key');
        $subject = $this->resolveSubject();

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
            'icon' => '/icon-192.png',
            'badge' => '/icon-192.png',
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

            $defaultOptions = [
                'TTL' => 86400,
                'urgency' => 'high',
            ];

            $webPush = new WebPush($auth, $defaultOptions);

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

    /**
     * Send an immediate test push notification to user's registered browsers
     */
    public function sendTestNotification(User $user): array
    {
        $subscriptions = PushSubscription::where('user_id', $user->id)->get()->unique('endpoint_hash');
        if ($subscriptions->isEmpty()) {
            return [
                'success' => false,
                'message' => 'هیچ مرورگری برای دریافت اعلان برای این کاربر ثبت نشده است.',
            ];
        }

        $publicKey = config('webpush.vapid.public_key');
        $privateKey = config('webpush.vapid.private_key');
        $subject = $this->resolveSubject();

        $payload = json_encode([
            'title' => 'اعلان تست GetNumber',
            'body' => 'این یک پیامک تست پس‌زمینه از سامانه است.',
            'icon' => '/icon-192.png',
            'badge' => '/icon-192.png',
            'tag' => 'getnumber-test-' . time(),
            'data' => [
                'url' => '/dashboard',
                'sender' => 'سامانه GetNumber',
            ],
        ], JSON_UNESCAPED_UNICODE);

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => $subject,
                    'publicKey' => $publicKey,
                    'privateKey' => $privateKey,
                ],
            ], [
                'TTL' => 3600,
                'urgency' => 'high',
            ]);

            foreach ($subscriptions as $sub) {
                $webPushSubscription = Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'publicKey' => $sub->public_key,
                    'authToken' => $sub->auth_token,
                    'contentEncoding' => $sub->content_encoding ?: 'aes128gcm',
                ]);
                $webPush->queueNotification($webPushSubscription, $payload);
            }

            $successCount = 0;
            $failCount = 0;
            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    $successCount++;
                } else {
                    $failCount++;
                }
            }

            return [
                'success' => $successCount > 0,
                'message' => "ارسال اعلان تست انجام شد (موفق: {$successCount}، ناموفق: {$failCount}).",
                'success_count' => $successCount,
                'fail_count' => $failCount,
            ];
        } catch (\Throwable $e) {
            Log::error('[WebPush] Test notification error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'خطا در ارسال اعلان تست: ' . $e->getMessage(),
            ];
        }
    }
}

