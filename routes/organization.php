<?php

use App\Http\Controllers\ApiDocsController;
use App\Http\Controllers\Organization\ApiTokenController;
use App\Http\Controllers\Organization\BulkMessageController;
use App\Http\Controllers\Organization\ConferenceController;
use App\Http\Controllers\Organization\LineManagementController;
use App\Http\Controllers\Organization\NumberRequestController;
use App\Http\Controllers\Organization\OrgContactController;
use App\Http\Controllers\Organization\OrgDashboardController;
use App\Http\Controllers\Organization\OrgWalletController;
use App\Http\Controllers\Organization\TeamMemberController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('org')->name('org.')->group(function () {
    Route::get('/', [OrgDashboardController::class, 'index'])->name('dashboard');

    // Team & Staff Management (One dedicated line per employee)
    Route::get('/team', [TeamMemberController::class, 'index'])->name('team.index');
    Route::post('/team', [TeamMemberController::class, 'store'])->name('team.store');
    Route::patch('/team/{member}', [TeamMemberController::class, 'update'])->name('team.update');
    Route::delete('/team/{member}', [TeamMemberController::class, 'destroy'])->name('team.destroy');
    Route::post('/team/{member}/assign-line', [TeamMemberController::class, 'assignLine'])->name('team.assign_line');
    Route::post('/team/{member}/unassign-line', [TeamMemberController::class, 'unassignLine'])->name('team.unassign_line');

    // Organization Lines Inventory
    Route::get('/lines', [LineManagementController::class, 'index'])->name('lines.index');

    // Enterprise Number Requests (4-8 Digits, Rond & Short Identifiers)
    Route::get('/requests', [NumberRequestController::class, 'index'])->name('requests.index');
    Route::post('/requests', [NumberRequestController::class, 'store'])->name('requests.store');
    Route::post('/requests/check-availability', [NumberRequestController::class, 'checkAvailability'])->name('requests.check_availability');
    Route::get('/requests/suggestions', [NumberRequestController::class, 'suggestions'])->name('requests.suggestions');
    Route::delete('/requests/{numberRequest}', [NumberRequestController::class, 'destroy'])->name('requests.destroy');

    // Enterprise API Tokens Management
    Route::get('/api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
    Route::post('/api-tokens', [ApiTokenController::class, 'store'])->name('api-tokens.store');
    Route::post('/api-tokens/{token}/rotate', [ApiTokenController::class, 'rotate'])->name('api-tokens.rotate');
    Route::post('/api-tokens/{token}/revoke', [ApiTokenController::class, 'revoke'])->name('api-tokens.revoke');
    Route::delete('/api-tokens/{token}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');

    // Enterprise API Documentation
    Route::get('/docs', [ApiDocsController::class, 'index'])->name('docs');

    // Enterprise Bulk SMS Engine
    Route::get('/bulk-sms', [BulkMessageController::class, 'index'])->name('bulk_sms.index');
    Route::post('/bulk-sms/estimate', [BulkMessageController::class, 'estimate'])->name('bulk_sms.estimate');
    Route::post('/bulk-sms/send', [BulkMessageController::class, 'send'])->name('bulk_sms.send');
    Route::get('/bulk-sms/batches/{batchId}', [BulkMessageController::class, 'batchStatus'])->name('bulk_sms.batch_status');

    // Shared Corporate Directory
    Route::get('/contacts', [OrgContactController::class, 'index'])->name('contacts.index');
    Route::post('/contacts', [OrgContactController::class, 'store'])->name('contacts.store');
    Route::delete('/contacts/{contact}', [OrgContactController::class, 'destroy'])->name('contacts.destroy');

    // Corporate Ledger & Wallet
    Route::get('/wallet', [OrgWalletController::class, 'index'])->name('wallet.index');
    Route::post('/wallet/deposit', [OrgWalletController::class, 'deposit'])->name('wallet.deposit');
    Route::post('/wallet/transfer-from-personal', [OrgWalletController::class, 'transferFromPersonal'])->name('wallet.transfer_from_personal');

    // Enterprise Video Conference Infrastructure (Organization Portal)
    Route::get('/conference', [ConferenceController::class, 'index'])->name('conference.index');
    Route::post('/conference', [ConferenceController::class, 'store'])->name('conference.store');
    Route::delete('/conference/{uuid}', [ConferenceController::class, 'destroy'])->name('conference.destroy');
    Route::get('/conference/{uuid}/lobby', [ConferenceController::class, 'lobby'])->name('conference.lobby');
    Route::post('/conference/{uuid}/join', [ConferenceController::class, 'join'])->name('conference.join');
    Route::get('/conference/{uuid}/room', [ConferenceController::class, 'room'])->name('conference.room');
    Route::get('/conference/{uuid}/state', [ConferenceController::class, 'state'])->name('conference.state');
    Route::post('/conference/{uuid}/signal', [ConferenceController::class, 'sendSignal'])->name('conference.signal');
    Route::post('/conference/{uuid}/media-state', [ConferenceController::class, 'updateMediaState'])->name('conference.media_state');
    Route::post('/conference/{uuid}/lock', [ConferenceController::class, 'toggleLock'])->name('conference.lock');
    Route::post('/conference/{uuid}/webinar', [ConferenceController::class, 'toggleWebinar'])->name('conference.webinar');
    Route::post('/conference/{uuid}/admit/{participant}', [ConferenceController::class, 'admitParticipant'])->name('conference.admit');
    Route::post('/conference/{uuid}/reject/{participant}', [ConferenceController::class, 'rejectParticipant'])->name('conference.reject');
    Route::post('/conference/{uuid}/kick/{participant}', [ConferenceController::class, 'kickParticipant'])->name('conference.kick');
    Route::post('/conference/{uuid}/mute/{participant}', [ConferenceController::class, 'toggleMute'])->name('conference.mute');
    Route::post('/conference/{uuid}/mute-all', [ConferenceController::class, 'muteAll'])->name('conference.mute_all');
    Route::post('/conference/{uuid}/screen-share/{participant}', [ConferenceController::class, 'grantScreenShare'])->name('conference.grant_screen_share');
    Route::post('/conference/{uuid}/start-screen-share', [ConferenceController::class, 'startScreenShare'])->name('conference.start_screen_share');
    Route::post('/conference/{uuid}/stop-screen-share', [ConferenceController::class, 'stopScreenShare'])->name('conference.stop_screen_share');
    Route::post('/conference/{uuid}/raise-hand', [ConferenceController::class, 'toggleRaiseHand'])->name('conference.raise_hand');
    Route::post('/conference/{uuid}/leave', [ConferenceController::class, 'leave'])->name('conference.leave');
    Route::post('/conference/{uuid}/end', [ConferenceController::class, 'endRoom'])->name('conference.end');
    Route::post('/conference/{uuid}/messages', [ConferenceController::class, 'sendMessage'])->name('conference.messages.store');
    Route::post('/conference/{uuid}/polls', [ConferenceController::class, 'createPoll'])->name('conference.polls.store');
    Route::post('/conference/{uuid}/polls/{poll}/close', [ConferenceController::class, 'closePoll'])->name('conference.polls.close');
    Route::post('/conference/{uuid}/polls/{poll}/vote', [ConferenceController::class, 'votePoll'])->name('conference.polls.vote');
    Route::get('/conference/{uuid}/export-attendance', [ConferenceController::class, 'exportAttendance'])->name('conference.export_attendance');
});
