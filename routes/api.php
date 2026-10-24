<?php

use App\Http\Controllers\Api\V1\PhoneNumberController;
use App\Http\Controllers\Api\V1\PingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth.api_token', 'throttle:api-token'])->group(function () {
    Route::get('/ping', PingController::class)->name('api.v1.ping');
    Route::get('/numbers', [PhoneNumberController::class, 'index'])->name('api.v1.numbers');
});
