<?php

use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\PhoneNumberController as AdminPhoneNumberController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\User\PhoneNumberController as UserPhoneNumberController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

// Standard User Routes
Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');

    Route::get('/numbers', [UserPhoneNumberController::class, 'index'])->name('numbers.index');
    Route::post('/numbers', [UserPhoneNumberController::class, 'store'])->name('numbers.store');
    Route::patch('/numbers/{number}', [UserPhoneNumberController::class, 'update'])->name('numbers.update');
    Route::delete('/numbers/{number}', [UserPhoneNumberController::class, 'destroy'])->name('numbers.destroy');

    Route::get('/requests', [\App\Http\Controllers\User\NumberRequestController::class, 'index'])->name('requests.index');
    Route::post('/requests', [\App\Http\Controllers\User\NumberRequestController::class, 'store'])->name('requests.store');
    Route::delete('/requests/{numberRequest}', [\App\Http\Controllers\User\NumberRequestController::class, 'destroy'])->name('requests.destroy');

    // User API Token Management
    Route::get('/api-tokens', [\App\Http\Controllers\User\ApiTokenController::class, 'index'])->name('api-tokens.index');
    Route::post('/api-tokens', [\App\Http\Controllers\User\ApiTokenController::class, 'store'])->name('api-tokens.store');
    Route::post('/api-tokens/{token}/rotate', [\App\Http\Controllers\User\ApiTokenController::class, 'rotate'])->name('api-tokens.rotate');
    Route::post('/api-tokens/{token}/revoke', [\App\Http\Controllers\User\ApiTokenController::class, 'revoke'])->name('api-tokens.revoke');
    Route::delete('/api-tokens/{token}', [\App\Http\Controllers\User\ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Administration Routes (Strictly Server-Side Protected by Admin Middleware)
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');
    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::get('/users/search', [AdminUserController::class, 'search'])->name('users.search');
    Route::get('/users/create', [AdminUserController::class, 'create'])->name('users.create');
    Route::post('/users', [AdminUserController::class, 'store'])->name('users.store');
    Route::patch('/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
    Route::patch('/users/{user}/status', [AdminUserController::class, 'updateStatus'])->name('users.status');
    Route::get('/users/{user}/numbers', [AdminUserController::class, 'numbers'])->name('users.numbers');

    Route::get('/numbers', [AdminPhoneNumberController::class, 'index'])->name('numbers.index');
    Route::post('/numbers', [AdminPhoneNumberController::class, 'store'])->name('numbers.store');
    Route::patch('/numbers/{number}/status', [AdminPhoneNumberController::class, 'updateStatus'])->name('numbers.status');
    Route::post('/numbers/{number}/assign', [AdminPhoneNumberController::class, 'assign'])->name('numbers.assign');
    Route::post('/numbers/{number}/release', [AdminPhoneNumberController::class, 'release'])->name('numbers.release');

    Route::get('/requests', [\App\Http\Controllers\Admin\NumberRequestController::class, 'index'])->name('requests.index');
    Route::post('/requests/{numberRequest}/approve', [\App\Http\Controllers\Admin\NumberRequestController::class, 'approve'])->name('requests.approve');
    Route::post('/requests/{numberRequest}/reject', [\App\Http\Controllers\Admin\NumberRequestController::class, 'reject'])->name('requests.reject');

    // Admin API Token Management & Oversight
    Route::get('/api-tokens', [\App\Http\Controllers\Admin\ApiTokenController::class, 'index'])->name('api-tokens.index');
    Route::post('/api-tokens/{token}/revoke', [\App\Http\Controllers\Admin\ApiTokenController::class, 'revoke'])->name('api-tokens.revoke');
    Route::delete('/api-tokens/{token}', [\App\Http\Controllers\Admin\ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');
});

require __DIR__.'/auth.php';

