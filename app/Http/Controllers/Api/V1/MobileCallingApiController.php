<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Communication\VoiceCallServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\MobileDevice;
use App\Models\VoiceCall;
use App\Services\Communication\DeviceRegistryService;
use App\Services\Communication\IceServerProvider;
use App\Services\Communication\NetworkModeManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileCallingApiController extends Controller
{
    public function __construct(
        protected DeviceRegistryService $deviceRegistry,
        protected VoiceCallServiceInterface $voiceCallService,
        protected IceServerProvider $iceServerProvider,
        protected NetworkModeManager $networkModeManager,
    ) {}

    /**
     * Register or update a mobile device installation and push tokens.
     * POST /api/v1/mobile/devices/register
     */
    public function registerDevice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_uuid' => ['required', 'string', 'max:64'],
            'platform' => ['required', 'string', 'in:ios,android,web'],
            'app_version' => ['nullable', 'string', 'max:32'],
            'os_version' => ['nullable', 'string', 'max:32'],
            'device_model' => ['nullable', 'string', 'max:64'],
            'push_provider' => ['nullable', 'string', 'max:32'],
            'voip_push_token' => ['nullable', 'string', 'max:512'],
            'notification_push_token' => ['nullable', 'string', 'max:512'],
            'local_push_connected' => ['nullable', 'boolean'],
            'network_mode' => ['nullable', 'string', 'max:16'],
            'ssid' => ['nullable', 'string', 'max:64'],
            'issue_device_token' => ['nullable', 'boolean'],
        ]);

        $result = $this->deviceRegistry->registerOrUpdateDevice(
            user: $request->user(),
            data: $validated,
            ipAddress: $request->ip()
        );

        /** @var MobileDevice $device */
        $device = $result['device'];

        return response()->json([
            'success' => true,
            'bridge_version' => '1.0.0',
            'device' => [
                'id' => $device->id,
                'device_uuid' => $device->device_uuid,
                'platform' => $device->platform,
                'app_version' => $device->app_version,
                'os_version' => $device->os_version,
                'push_provider' => $device->push_provider,
                'has_voip_token' => ! empty($device->voip_push_token_hash),
                'has_notification_token' => ! empty($device->notification_push_token_hash),
                'local_push_connected' => $device->local_push_connected,
                'network_mode' => $device->network_mode,
                'status' => $device->status,
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            ],
            'device_auth_token' => $result['device_auth_token'],
            'local_push' => [
                'ws_url' => $result['local_push_ws_url'],
                'ticket' => $result['local_push_ticket'],
                'expires_at' => $result['local_push_expires_at'],
                'allowed_intranet_ssids' => $result['allowed_intranet_ssids'],
            ],
            'server_network_mode' => $this->networkModeManager->currentMode(),
        ]);
    }

    /**
     * Revoke a mobile device installation on logout.
     * DELETE /api/v1/mobile/devices/{deviceUuid}
     */
    public function revokeDevice(Request $request, string $deviceUuid): JsonResponse
    {
        $revoked = $this->deviceRegistry->revokeDevice($request->user(), $deviceUuid);

        return response()->json([
            'success' => $revoked,
            'device_uuid' => $deviceUuid,
            'status' => $revoked ? MobileDevice::STATUS_REVOKED : 'not_found',
        ], $revoked ? 200 : 404);
    }

    /**
     * Record device heartbeat and local push connectivity telemetry.
     * POST /api/v1/mobile/devices/heartbeat
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_uuid' => ['required', 'string', 'max:64'],
            'network_mode' => ['nullable', 'string', 'max:16'],
            'local_push_connected' => ['nullable', 'boolean'],
            'ssid' => ['nullable', 'string', 'max:64'],
        ]);

        $device = $this->deviceRegistry->recordHeartbeat(
            user: $request->user(),
            deviceUuid: $validated['device_uuid'],
            telemetry: $validated,
            ipAddress: $request->ip()
        );

        if (! $device) {
            return response()->json([
                'success' => false,
                'code' => 'DEVICE_NOT_REGISTERED',
                'message' => __('controllers.api.v1_mobile.device_not_registered'),
            ], 404);
        }

        // Expire any timed-out ringing calls
        $this->voiceCallService->expireTimedOutRingingCalls();

        $activeIncoming = VoiceCall::where('user_id', $request->user()->id)
            ->where('direction', 'inbound')
            ->where('status', VoiceCall::STATE_RINGING)
            ->where('created_at', '>=', now()->subSeconds(60))
            ->latest('id')
            ->first();

        return response()->json([
            'success' => true,
            'server_time_ms' => (int) round(microtime(true) * 1000),
            'network_mode' => $this->networkModeManager->currentMode(),
            'device_status' => $device->status,
            'active_incoming_call' => $activeIncoming ? $this->formatCallPayload($activeIncoming, $request->user()) : null,
        ]);
    }

    /**
     * Get the user's current ringing or active call by call_uuid or numeric ID.
     * GET /api/v1/mobile/calls/{callUuid}
     */
    public function showCall(Request $request, string $callUuid): JsonResponse
    {
        $this->voiceCallService->expireTimedOutRingingCalls();

        $call = $this->resolveUserCall($request->user()->id, $callUuid);
        if (! $call) {
            return response()->json([
                'success' => false,
                'code' => 'CALL_NOT_FOUND',
                'message' => __('controllers.api.v1_mobile.call_not_found_or_denied'),
            ], 404);
        }

        return response()->json([
            'success' => true,
            'call' => $this->formatCallPayload($call, $request->user()),
        ]);
    }

    /**
     * Acknowledge that a push invite arrived and the device is ringing.
     * POST /api/v1/mobile/calls/{callUuid}/ringing-ack
     */
    public function ringingAck(Request $request, string $callUuid): JsonResponse
    {
        $validated = $request->validate([
            'device_uuid' => ['required', 'string', 'max:64'],
            'provider' => ['nullable', 'string', 'max:32'],
        ]);

        $call = $this->resolveUserCall($request->user()->id, $callUuid, 'inbound');
        if (! $call) {
            return response()->json([
                'success' => false,
                'code' => 'CALL_NOT_FOUND',
                'message' => __('controllers.api.v1_mobile.call_not_found'),
            ], 404);
        }

        $meta = $call->metadata ?? [];
        $acks = $meta['ringing_acks'] ?? [];
        $acks[$validated['device_uuid']] = [
            'provider' => $validated['provider'] ?? 'native',
            'acked_at_ms' => (int) round(microtime(true) * 1000),
        ];
        $meta['ringing_acks'] = $acks;
        $call->update(['metadata' => $meta]);

        return response()->json([
            'success' => true,
            'call_uuid' => $call->call_uuid,
            'status' => $call->status,
            'canonical_status' => $call->canonicalStatus(),
        ]);
    }

    /**
     * Atomically answer an incoming call from Native CallKit / Android Telecom.
     * POST /api/v1/mobile/calls/{callUuid}/answer
     */
    public function answerCall(Request $request, string $callUuid): JsonResponse
    {
        $validated = $request->validate([
            'device_uuid' => ['nullable', 'string', 'max:64'],
            'with_video' => ['nullable', 'boolean'],
        ]);

        $call = $this->resolveUserCall($request->user()->id, $callUuid, 'inbound');
        if (! $call) {
            return response()->json([
                'success' => false,
                'code' => 'CALL_NOT_FOUND',
                'message' => __('controllers.api.v1_mobile.call_not_found'),
            ], 404);
        }

        try {
            $accepted = $this->voiceCallService->acceptCallAtomically(
                voiceCall: $call,
                user: $request->user(),
                deviceUuid: $validated['device_uuid'] ?? null
            );

            return response()->json([
                'success' => true,
                'call' => $this->formatCallPayload($accepted, $request->user()),
            ]);
        } catch (\RuntimeException $e) {
            $httpCode = in_array($e->getCode(), [409, 410], true) ? $e->getCode() : 409;
            return response()->json([
                'success' => false,
                'code' => $e->getMessage(),
                'message' => $e->getMessage() === 'CALL_ALREADY_ACCEPTED_ON_ANOTHER_DEVICE'
                    ? __('controllers.api.v1_mobile.call_answered_elsewhere')
                    : __('controllers.api.v1_mobile.call_not_ringing'),
                'accepted_device_id' => $call->fresh()?->accepted_device_id,
            ], $httpCode);
        }
    }

    /**
     * Atomically reject an incoming call from Native CallKit / Android Telecom.
     * POST /api/v1/mobile/calls/{callUuid}/reject
     */
    public function rejectCall(Request $request, string $callUuid): JsonResponse
    {
        $validated = $request->validate([
            'device_uuid' => ['nullable', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:64'],
        ]);

        $call = $this->resolveUserCall($request->user()->id, $callUuid, 'inbound');
        if (! $call) {
            return response()->json([
                'success' => false,
                'code' => 'CALL_NOT_FOUND',
                'message' => __('controllers.api.v1_mobile.call_not_found'),
            ], 404);
        }

        try {
            $rejected = $this->voiceCallService->rejectCallAtomically(
                voiceCall: $call,
                user: $request->user(),
                deviceUuid: $validated['device_uuid'] ?? null,
                reason: $validated['reason'] ?? 'rejected_by_callee'
            );

            return response()->json([
                'success' => true,
                'call' => $this->formatCallPayload($rejected, $request->user()),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'code' => $e->getMessage(),
                'message' => __('controllers.api.v1_mobile.cannot_reject_call'),
            ], 409);
        }
    }

    /**
     * End an active or ringing call from Native CallKit / Android Telecom.
     * POST /api/v1/mobile/calls/{callUuid}/end
     */
    public function endCall(Request $request, string $callUuid): JsonResponse
    {
        $validated = $request->validate([
            'device_uuid' => ['nullable', 'string', 'max:64'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:64'],
        ]);

        $call = $this->resolveUserCall($request->user()->id, $callUuid);
        if (! $call) {
            return response()->json([
                'success' => false,
                'code' => 'CALL_NOT_FOUND',
                'message' => __('controllers.api.v1_mobile.call_not_found'),
            ], 404);
        }

        $ended = $this->voiceCallService->endCall(
            call: $call,
            durationSeconds: $validated['duration_seconds'] ?? null,
            endReason: $validated['reason'] ?? null
        );

        return response()->json([
            'success' => true,
            'call' => $this->formatCallPayload($ended, $request->user()),
        ]);
    }

    /**
     * End-to-End Network Mode Probe endpoint.
     * GET /api/v1/network/probe
     */
    public function networkProbe(Request $request): JsonResponse
    {
        $liveBridge = $request->boolean('live', false);
        $report = $this->networkModeManager->probeHealth($liveBridge);

        return response()->json(array_merge(['success' => true], $report));
    }

    protected function resolveUserCall(int $userId, string $callUuidOrId, ?string $preferredDirection = null): ?VoiceCall
    {
        $query = VoiceCall::where('user_id', $userId);

        if (ctype_digit($callUuidOrId)) {
            $query->where('id', (int) $callUuidOrId);
        } else {
            $query->where('call_uuid', trim($callUuidOrId));
        }

        if ($preferredDirection) {
            $preferred = (clone $query)->where('direction', $preferredDirection)->latest('id')->first();
            if ($preferred) {
                return $preferred;
            }
        }

        return $query->latest('id')->first();
    }

    protected function formatCallPayload(VoiceCall $call, $user): array
    {
        return [
            'id' => $call->id,
            'call_uuid' => $call->call_uuid ?: ('call-' . $call->id),
            'source' => $call->source_identifier,
            'destination' => $call->destination,
            'direction' => $call->direction,
            'status' => $call->status,
            'canonical_status' => $call->canonicalStatus(),
            'has_video' => (bool) $call->has_video,
            'accepted_device_id' => $call->accepted_device_id,
            'end_reason' => $call->end_reason,
            'started_at' => $call->started_at?->toIso8601String(),
            'answered_at' => $call->answered_at?->toIso8601String(),
            'ended_at' => $call->ended_at?->toIso8601String(),
            'duration_seconds' => $call->duration_seconds,
            'cost' => (float) $call->cost,
            'bridge_session_id' => $call->metadata['bridge_session_id'] ?? null,
            'bridge_ws_endpoint' => $call->metadata['bridge_ws_endpoint'] ?? null,
            'ping_ms' => $call->metadata['ping_ms'] ?? 22,
            'is_internal' => (bool) ($call->metadata['is_internal'] ?? true),
            'rtc_config' => $this->iceServerProvider->rtcConfigFor($user),
        ];
    }
}
