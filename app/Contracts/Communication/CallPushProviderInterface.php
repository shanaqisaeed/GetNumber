<?php

namespace App\Contracts\Communication;

use App\DTOs\CallPushPayload;
use App\DTOs\PushDeliveryResult;
use App\Models\MobileDevice;

interface CallPushProviderInterface
{
    public function providerKey(): string;

    public function supports(MobileDevice $device, string $networkMode): bool;

    public function isHealthy(MobileDevice $device): bool;

    public function sendCallInvite(MobileDevice $device, CallPushPayload $payload): PushDeliveryResult;

    public function sendCallControl(MobileDevice $device, string $callUuid, string $eventType, array $extra = []): PushDeliveryResult;
}
