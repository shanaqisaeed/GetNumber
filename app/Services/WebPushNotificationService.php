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
     * Check if WebPush library is installed and available
     */
    public function isSupported(): bool
    {
        return class_exists(\Minishlink\WebPush\WebPush::class);
    }

    /**
     * Get the VAPID Public Key for client subscription
     */
    public function getPublicKey(): string
    {
        return config('webpush.vapid.public_key', '');
    }

    /**
     * Resolve valid VAPID subject claim (RFC 8292 mandates mailto: or public https:// domain;
     * Apple web.push.apple.com and Google FCM reject http://, localhost, and raw IP subjects).
     */
    public function resolveSubject(): string
    {
        $subject = trim((string) config('webpush.vapid.subject', ''));
        if ($subject === '') {
            $subject = trim((string) config('app.url', ''));
        }

        if (str_starts_with($subject, 'mailto:')) {
            return $subject;
        }

        if (
            $subject === '' ||
            str_starts_with($subject, 'http://') ||
            str_contains($subject, 'localhost') ||
            str_contains($subject, '127.0.0.1') ||
            str_ends_with($subject, '.local') ||
            str_ends_with($subject, '.test') ||
            preg_match('/^https?:\/\/\d+\.\d+\.\d+\.\d+/', $subject)
        ) {
            return 'mailto:support@ravyno.com';
        }

        if (!str_starts_with($subject, 'https://')) {
            return 'mailto:support@ravyno.com';
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

        if (!$this->isSupported()) {
            Log::info('[WebPush] minishlink/web-push package is not installed on this host. Skipping push notification.');
            return;
        }

        // On Windows environments, ensure OPENSSL_CONF is configured if present
        if (!getenv('OPENSSL_CONF') && file_exists('E:\\php\\extras\\ssl\\openssl.cnf')) {
            putenv('OPENSSL_CONF=E:\\php\\extras\\ssl\\openssl.cnf');
        }

        // Privacy rule: Only state who sent the SMS, never the message body!
        $sender = $message->peer_number ?: $message->sender ?: __('services.web_push.unknown_sender');
        $payload = json_encode([
            'title' => __('services.web_push.new_message_title'),
            'body' => __('services.web_push.new_message_body', ['sender' => $sender]),
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
     * Send Web Push notification to user's registered browser/phone when a voice call is incoming
     */
    public function sendVoiceCallNotification(\App\Models\VoiceCall $call): void
    {
        $userId = $call->user_id;
        if (! $userId) {
            return;
        }

        $subscriptions = PushSubscription::where('user_id', $userId)
            ->get()
            ->unique('endpoint_hash');

        if ($subscriptions->isEmpty()) {
            Log::info("[WebPush] No push subscriptions found for callee user ID: {$userId}");
            return;
        }

        $publicKey = config('webpush.vapid.public_key');
        $privateKey = config('webpush.vapid.private_key');
        $subject = $this->resolveSubject();

        if (! $publicKey || ! $privateKey) {
            return;
        }

        if (! $this->isSupported()) {
            Log::info('[WebPush] minishlink/web-push package is not installed on this host. Skipping voice call push.');
            return;
        }

        if (! getenv('OPENSSL_CONF') && file_exists('E:\\php\\extras\\ssl\\openssl.cnf')) {
            putenv('OPENSSL_CONF=E:\\php\\extras\\ssl\\openssl.cnf');
        }

        $caller = $call->source_identifier ?: __('services.web_push.unknown_sender');
        $destLine = $call->destination ? __('services.web_push.incoming_call_on_line', ['destination' => $call->destination]) : '';
        $body = trim(__('services.web_push.incoming_call_body', ['caller' => $caller, 'line' => $destLine]));

        $hasVideoFlag = $call->has_video ? '&caller_has_video=1' : '';
        $payload = json_encode([
            'title' => __('services.web_push.incoming_call_title'),
            'body' => $body,
            'icon' => '/icon-192.png',
            'badge' => '/icon-192.png',
            'tag' => 'getnumber-call-' . $call->id,
            'renotify' => true,
            'requireInteraction' => true,
            'vibrate' => [500, 250, 500, 250, 500, 250, 500, 250, 500, 250, 500],
            'actions' => [
                [
                    'action' => 'answer',
                    'title' => __('services.web_push.action_answer'),
                    'icon' => '/icon-192.png',
                ],
                [
                    'action' => 'decline',
                    'title' => __('services.web_push.action_decline'),
                    'icon' => '/icon-192.png',
                ],
            ],
            'data' => [
                'type' => 'voice_call',
                'url' => "/calls?incoming_call_id={$call->id}&auto_answer=1{$hasVideoFlag}",
                'callId' => $call->id,
                'callUuid' => $call->call_uuid,
                'hasVideo' => (bool) $call->has_video,
                'caller' => $caller,
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

            // RFC 8030 Section 5.3 valid urgency levels: very-low | low | normal | high
            $defaultOptions = [
                'TTL' => 60,
                'urgency' => 'high',
                'topic' => 'call-' . $call->id,
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
                    Log::info("[WebPush] Incoming call push delivered to: " . substr($endpoint, 0, 45) . '...');
                } else {
                    Log::warning("[WebPush] Incoming call push failed for endpoint: " . substr($endpoint, 0, 45) . "... Reason: " . $report->getReason());
                    if ($report->isSubscriptionExpired()) {
                        PushSubscription::where('endpoint_hash', PushSubscription::hashEndpoint($endpoint))->delete();
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('[WebPush] Failed sending voice call push notification: ' . $e->getMessage());
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
                'message' => __('services.web_push.no_subscriptions'),
            ];
        }

        if (!$this->isSupported()) {
            return [
                'success' => false,
                'message' => __('services.web_push.package_not_installed'),
            ];
        }

        $publicKey = config('webpush.vapid.public_key');
        $privateKey = config('webpush.vapid.private_key');
        $subject = $this->resolveSubject();

        $payload = json_encode([
            'title' => __('services.web_push.test_title'),
            'body' => __('services.web_push.test_body'),
            'icon' => '/icon-192.png',
            'badge' => '/icon-192.png',
            'tag' => 'getnumber-test-' . time(),
            'data' => [
                'url' => '/dashboard',
                'sender' => __('services.web_push.test_sender'),
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
                'message' => __('services.web_push.test_sent_summary', ['success' => $successCount, 'fail' => $failCount]),
                'success_count' => $successCount,
                'fail_count' => $failCount,
            ];
        } catch (\Throwable $e) {
            Log::error('[WebPush] Test notification error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => __('services.web_push.test_failed', ['error' => $e->getMessage()]),
            ];
        }
    }
}