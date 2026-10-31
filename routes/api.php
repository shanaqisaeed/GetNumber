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
});
