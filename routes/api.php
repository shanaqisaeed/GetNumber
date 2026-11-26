<?php

use App\Http\Controllers\Api\V1\PhoneNumberController;
use App\Http\Controllers\Api\V1\PingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth.api_token', 'throttle:api-token'])->group(function () {
    Route::get('/ping', PingController::class)->name('api.v1.ping');
    Route::get('/numbers', [PhoneNumberController::class, 'index'])->name('api.v1.numbers');
    Route::post('/numbers/assign', [PhoneNumberController::class, 'assign'])->name('api.v1.numbers.assign');

    Route::post('/messages/inbound', [\App\Http\Controllers\Api\V1\MessageController::class, 'store'])->name('api.v1.messages.inbound');
    Route::post('/messages', [\App\Http\Controllers\Api\V1\MessageController::class, 'store'])->name('api.v1.messages.store');
    Route::get('/messages', [\App\Http\Controllers\Api\V1\MessageController::class, 'index'])->name('api.v1.messages.index');
    Route::get('/messages/poll', [\App\Http\Controllers\Api\V1\MessageController::class, 'poll'])->name('api.v1.messages.poll');
    Route::get('/messages/resync', [\App\Http\Controllers\Api\V1\MessageController::class, 'resync'])->name('api.v1.messages.resync');
    Route::get('/messages/batches/{batchId}', [\App\Http\Controllers\Api\V1\MessageController::class, 'batchStatus'])->name('api.v1.messages.batch.status');

    // Multi-Service Platform API Contracts (Phase 1)
    Route::get('/services', [\App\Http\Controllers\Api\V1\ServiceCatalogApiController::class, 'index'])->name('api.v1.services.index');
    Route::post('/services/validate', [\App\Http\Controllers\Api\V1\ServiceCatalogApiController::class, 'validateOrder'])->name('api.v1.services.validate');
    Route::post('/services/orders', [\App\Http\Controllers\Api\V1\ServiceCatalogApiController::class, 'storeOrder'])->name('api.v1.services.orders.store');
    Route::get('/services/allocations', [\App\Http\Controllers\Api\V1\ServiceCatalogApiController::class, 'allocations'])->name('api.v1.services.allocations.index');
    Route::post('/services/allocations/{allocation:uuid}/sub-accounts', [\App\Http\Controllers\Api\V1\ServiceCatalogApiController::class, 'storeSubAccount'])->name('api.v1.services.sub_accounts.store');
});

Route::post('/telemetry/errors', [\App\Http\Controllers\HealthCheckController::class, 'reportClientError'])
    ->middleware('throttle:60,1')
    ->name('api.telemetry.errors');

Route::post('/voice/bridge-event', [\App\Http\Controllers\User\VoiceCallController::class, 'bridgeWebhook'])
    ->name('api.voice.bridge_event');
Route::post('/voice/bridge-callback', [\App\Http\Controllers\User\VoiceCallController::class, 'bridgeWebhook'])
    ->name('api.voice.bridge_callback');

// Network Mode Health Probe (Public/Intranet non-blocking probe)
Route::get('/v1/network/probe', [\App\Http\Controllers\Api\V1\MobileCallingApiController::class, 'networkProbe'])
    ->middleware('throttle:120,1')
    ->name('api.v1.network.probe');

// Native Mobile Calling & Device Registry API Contracts (Authenticated via Web Session OR Device Bearer Token)
Route::prefix('v1/mobile')->middleware(['web', 'auth.mobile', 'throttle:120,1'])->group(function () {
    Route::post('/devices/register', [\App\Http\Controllers\Api\V1\MobileCallingApiController::class, 'registerDevice'])
        ->name('api.v1.mobile.devices.register');
    Route::delete('/devices/{deviceUuid}', [\App\Http\Controllers\Api\V1\MobileCallingApiController::class, 'revokeDevice'])
        ->name('api.v1.mobile.devices.revoke');
    Route::post('/devices/heartbeat', [\App\Http\Controllers\Api\V1\MobileCallingApiController::class, 'heartbeat'])
        ->name('api.v1.mobile.devices.heartbeat');

    Route::get('/calls/{callUuid}', [\App\Http\Controllers\Api\V1\MobileCallingApiController::class, 'showCall'])
        ->name('api.v1.mobile.calls.show');
    Route::post('/calls/{callUuid}/ringing-ack', [\App\Http\Controllers\Api\V1\MobileCallingApiController::class, 'ringingAck'])
        ->name('api.v1.mobile.calls.ringing_ack');
    Route::post('/calls/{callUuid}/answer', [\App\Http\Controllers\Api\V1\MobileCallingApiController::class, 'answerCall'])
        ->name('api.v1.mobile.calls.answer');
    Route::post('/calls/{callUuid}/reject', [\App\Http\Controllers\Api\V1\MobileCallingApiController::class, 'rejectCall'])
        ->name('api.v1.mobile.calls.reject');
    Route::post('/calls/{callUuid}/end', [\App\Http\Controllers\Api\V1\MobileCallingApiController::class, 'endCall'])
        ->name('api.v1.mobile.calls.end');
});
