<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class MobileDevice extends Model
{
    use HasFactory;

    public const PLATFORM_IOS = 'ios';
    public const PLATFORM_ANDROID = 'android';
    public const PLATFORM_WEB = 'web';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'device_uuid',
        'user_id',
        'platform',
        'app_version',
        'os_version',
        'device_model',
        'push_provider',
        'voip_push_token_encrypted',
        'voip_push_token_hash',
        'notification_push_token_encrypted',
        'notification_push_token_hash',
        'local_push_connection_id',
        'local_push_connected',
        'network_mode',
        'last_known_ip',
        'last_known_ssid',
        'status',
        'api_token_id',
        'last_seen_at',
        'revoked_at',
        'metadata',
    ];

    /**
     * Never expose encrypted push tokens or hashes in logs/JSON serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'voip_push_token_encrypted',
        'voip_push_token_hash',
        'notification_push_token_encrypted',
        'notification_push_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'local_push_connected' => 'boolean',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function apiToken(): BelongsTo
    {
        return $this->belongsTo(ApiToken::class, 'api_token_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->whereNull('revoked_at');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->revoked_at === null;
    }

    /**
     * Encrypt and store APNs VoIP token at rest.
     */
    public function setVoipPushToken(?string $plainToken): void
    {
        $clean = trim((string) $plainToken);
        if ($clean === '') {
            $this->voip_push_token_encrypted = null;
            $this->voip_push_token_hash = null;
            return;
        }

        $this->voip_push_token_encrypted = Crypt::encryptString($clean);
        $this->voip_push_token_hash = hash('sha256', $clean);
    }

    /**
     * Decrypt APNs VoIP token for outbound delivery only.
     */
    public function getDecryptedVoipPushToken(): ?string
    {
        if (empty($this->voip_push_token_encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString($this->voip_push_token_encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Encrypt and store FCM / APNs alert notification token at rest.
     */
    public function setNotificationPushToken(?string $plainToken): void
    {
        $clean = trim((string) $plainToken);
        if ($clean === '') {
            $this->notification_push_token_encrypted = null;
            $this->notification_push_token_hash = null;
            return;
        }

        $this->notification_push_token_encrypted = Crypt::encryptString($clean);
        $this->notification_push_token_hash = hash('sha256', $clean);
    }

    /**
     * Decrypt FCM / APNs notification token for outbound delivery only.
     */
    public function getDecryptedNotificationPushToken(): ?string
    {
        if (empty($this->notification_push_token_encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString($this->notification_push_token_encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Safe redacted fingerprint for diagnostic logs (never exposes raw token).
     */
    public function redactedTokenFingerprint(): string
    {
        $hash = $this->voip_push_token_hash ?: $this->notification_push_token_hash;
        if (! $hash) {
            return 'no-token';
        }

        return 'sha256:' . substr($hash, 0, 10) . '...';
    }
}
