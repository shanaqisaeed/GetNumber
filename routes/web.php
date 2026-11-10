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

// Public API Documentation
Route::get('/docs/api', [\App\Http\Controllers\ApiDocsController::class, 'index'])->name('api.docs');
Route::redirect('/docs', '/docs/api');

// System Health Checks (Liveness & Readiness Probes)
Route::get('/health/live', [\App\Http\Controllers\HealthCheckController::class, 'liveness'])->name('health.live');
Route::get('/health/ready', [\App\Http\Controllers\HealthCheckController::class, 'readiness'])->name('health.ready');

// XML Sitemap for Public Search Engines
Route::get('/sitemap.xml', function () {
    $baseUrl = url('/');
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    $xml .= '  <url>' . "\n";
    $xml .= '    <loc>' . htmlspecialchars($baseUrl) . '</loc>' . "\n";
    $xml .= '    <changefreq>weekly</changefreq>' . "\n";
    $xml .= '    <priority>1.0</priority>' . "\n";
    $xml .= '  </url>' . "\n";
    $xml .= '  <url>' . "\n";
    $xml .= '    <loc>' . htmlspecialchars($baseUrl . '/docs/api') . '</loc>' . "\n";
    $xml .= '    <changefreq>monthly</changefreq>' . "\n";
    $xml .= '    <priority>0.8</priority>' . "\n";
    $xml .= '  </url>' . "\n";
    $xml .= '</urlset>';

    return response($xml, 200, [
        'Content-Type' => 'application/xml; charset=utf-8',
        'X-Robots-Tag' => 'noindex',
    ]);
})->name('sitemap');

// Service Worker for Web Push Notifications
Route::get('/sw.js', function () {
    $swPath = public_path('sw.js');
    $content = file_exists($swPath)
        ? file_get_contents($swPath)
        : <<<'JS'
self.addEventListener('install', (e) => { e.waitUntil(self.skipWaiting()); });
self.addEventListener('activate', (e) => { e.waitUntil(self.clients.claim()); });
self.addEventListener('push', (e) => {
    let d = {};
    try { d = e.data ? e.data.json() : {}; } catch { d = { title: 'پیامک جدید', body: e.data ? e.data.text() : '' }; }
    e.waitUntil(self.registration.showNotification(d.title || 'پیامک جدید', {
        body: d.body || 'یک پیامک جدید دریافت شد.',
        icon: '/icon-192.png',
        badge: '/icon-192.png',
        tag: 'sms-' + Date.now(),
        renotify: true,
        vibrate: [200, 100, 200],
        data: d.data || { url: '/messages' }
    }));
});
self.addEventListener('notificationclick', (e) => {
    e.notification.close();
    const url = e.notification.data?.url || '/messages';
    e.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
            for (const c of list) { if (c.url && 'focus' in c) return c.focus(); }
            if (clients.openWindow) return clients.openWindow(url);
        })
    );
});
JS;

    return response($content, 200, [
        'Content-Type' => 'application/javascript; charset=utf-8',
        'Service-Worker-Allowed' => '/',
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
    ]);
})->name('webpush.serviceworker');

// Standard User Routes
Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\User\DashboardController::class, 'index'])->name('dashboard');

    Route::get('/numbers', [UserPhoneNumberController::class, 'index'])->name('numbers.index');
    Route::post('/numbers', [UserPhoneNumberController::class, 'store'])->name('numbers.store');
    Route::post('/numbers/check-availability', [UserPhoneNumberController::class, 'checkAvailability'])->name('numbers.check_availability');
    Route::get('/numbers/pool-suggestions', [UserPhoneNumberController::class, 'poolSuggestions'])->name('numbers.pool_suggestions');
    Route::patch('/numbers/{number}', [UserPhoneNumberController::class, 'update'])->name('numbers.update');
    Route::delete('/numbers/{number}', [UserPhoneNumberController::class, 'destroy'])->name('numbers.destroy');

    Route::get('/requests', [\App\Http\Controllers\User\NumberRequestController::class, 'index'])->name('requests.index');
    Route::post('/requests', [\App\Http\Controllers\User\NumberRequestController::class, 'store'])->name('requests.store');
    Route::delete('/requests/{numberRequest}', [\App\Http\Controllers\User\NumberRequestController::class, 'destroy'])->name('requests.destroy');

    // User SMS Messaging & Chat (Mobile style)
    Route::get('/messages', [\App\Http\Controllers\User\MessageController::class, 'index'])->name('messages.index');
    Route::post('/messages/send', [\App\Http\Controllers\User\MessageController::class, 'send'])->name('messages.send');
    Route::post('/messages/send-bulk', [\App\Http\Controllers\User\MessageController::class, 'sendBulk'])->name('messages.send_bulk');
    Route::post('/messages/estimate-cost', [\App\Http\Controllers\User\MessageController::class, 'estimateCost'])->name('messages.estimate_cost');
    Route::get('/messages/batches/{batchId}', [\App\Http\Controllers\User\MessageController::class, 'batchStatus'])->name('messages.batch.status');
    Route::get('/messages/resync', [\App\Http\Controllers\User\MessageController::class, 'resync'])->name('messages.resync');
    Route::get('/messages/conversation', [\App\Http\Controllers\User\MessageController::class, 'conversation'])->name('messages.conversation');
    Route::get('/messages/poll', [\App\Http\Controllers\User\MessageController::class, 'poll'])->name('messages.poll');
    Route::get('/messages/unread-summary', [\App\Http\Controllers\User\MessageController::class, 'unreadSummary'])->name('messages.unread_summary');
    Route::get('/messages/{message}', [\App\Http\Controllers\User\MessageController::class, 'show'])->name('messages.show');

    // User API Token Management
    Route::get('/api-tokens', [\App\Http\Controllers\User\ApiTokenController::class, 'index'])->name('api-tokens.index');
    Route::post('/api-tokens', [\App\Http\Controllers\User\ApiTokenController::class, 'store'])->name('api-tokens.store');
    Route::post('/api-tokens/{token}/rotate', [\App\Http\Controllers\User\ApiTokenController::class, 'rotate'])->name('api-tokens.rotate');
    Route::post('/api-tokens/{token}/revoke', [\App\Http\Controllers\User\ApiTokenController::class, 'revoke'])->name('api-tokens.revoke');
    Route::delete('/api-tokens/{token}', [\App\Http\Controllers\User\ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');

    // User Support Tickets
    Route::get('/tickets', [\App\Http\Controllers\User\TicketController::class, 'index'])->name('tickets.index');
    Route::get('/tickets/create', [\App\Http\Controllers\User\TicketController::class, 'create'])->name('tickets.create');
    Route::post('/tickets', [\App\Http\Controllers\User\TicketController::class, 'store'])->name('tickets.store');
    Route::get('/tickets/{ticket}', [\App\Http\Controllers\User\TicketController::class, 'show'])->name('tickets.show');
    Route::post('/tickets/{ticket}/reply', [\App\Http\Controllers\User\TicketController::class, 'reply'])->name('tickets.reply');
    Route::post('/tickets/{ticket}/close', [\App\Http\Controllers\User\TicketController::class, 'close'])->name('tickets.close');
    Route::get('/tickets/{ticket}/attachments/{attachment}', [\App\Http\Controllers\User\TicketController::class, 'downloadAttachment'])->name('tickets.attachments.download');

    // User Wallet & Ledger
    Route::get('/wallet', [\App\Http\Controllers\User\WalletController::class, 'index'])->name('wallet.index');
    Route::get('/wallet/transactions', [\App\Http\Controllers\User\WalletController::class, 'transactions'])->name('wallet.transactions');
    Route::get('/wallet/deposit', [\App\Http\Controllers\User\PaymentDepositController::class, 'create'])->name('wallet.deposit.create');
    Route::post('/wallet/deposit', [\App\Http\Controllers\User\PaymentDepositController::class, 'store'])->name('wallet.deposit.store');
    Route::get('/wallet/deposit/{trackingCode}', [\App\Http\Controllers\User\PaymentDepositController::class, 'show'])->name('wallet.deposit.show');
    Route::post('/wallet/deposit/{trackingCode}/submit-tx', [\App\Http\Controllers\User\PaymentDepositController::class, 'submitTx'])->name('wallet.deposit.submit-tx');
    Route::get('/wallet/deposit/{trackingCode}/status', [\App\Http\Controllers\User\PaymentDepositController::class, 'status'])->name('wallet.deposit.status');

    // Web Push Subscriptions & VAPID Key
    Route::get('/webpush/vapid-public-key', [\App\Http\Controllers\Api\WebPushController::class, 'vapidPublicKey'])->name('webpush.key');
    Route::post('/webpush/subscribe', [\App\Http\Controllers\Api\WebPushController::class, 'subscribe'])->name('webpush.subscribe');
    Route::post('/webpush/unsubscribe', [\App\Http\Controllers\Api\WebPushController::class, 'unsubscribe'])->name('webpush.unsubscribe');
    Route::post('/webpush/test', [\App\Http\Controllers\Api\WebPushController::class, 'testPush'])->name('webpush.test');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Multi-Provider User Identity Linking
    Route::post('/profile/identities/email', [\App\Http\Controllers\User\UserIdentityController::class, 'linkEmail'])->name('profile.identities.email');
    Route::post('/profile/identities/ldap', [\App\Http\Controllers\User\UserIdentityController::class, 'linkLdap'])->name('profile.identities.ldap');
    Route::delete('/profile/identities/{provider}', [\App\Http\Controllers\User\UserIdentityController::class, 'unlink'])->name('profile.identities.unlink');

    // Two-Factor Authentication & Account Security Management
    Route::get('/two-factor/status', [\App\Http\Controllers\User\TwoFactorAuthenticationController::class, 'status'])->name('two-factor.status');
    Route::post('/two-factor/enable', [\App\Http\Controllers\User\TwoFactorAuthenticationController::class, 'store'])->name('two-factor.enable');
    Route::post('/two-factor/confirm', [\App\Http\Controllers\User\TwoFactorAuthenticationController::class, 'confirm'])->name('two-factor.confirm');
    Route::delete('/two-factor/disable', [\App\Http\Controllers\User\TwoFactorAuthenticationController::class, 'destroy'])->name('two-factor.disable');
    Route::post('/two-factor/recovery-codes', [\App\Http\Controllers\User\TwoFactorAuthenticationController::class, 'regenerateRecoveryCodes'])->name('two-factor.recovery-codes');

    // Active Sessions & Devices Management
    Route::get('/sessions', [\App\Http\Controllers\User\SessionController::class, 'index'])->name('sessions.index');
    Route::post('/sessions/other-devices/logout', [\App\Http\Controllers\User\SessionController::class, 'destroyOther'])->name('sessions.logout_other');
    Route::delete('/sessions/{sessionId}', [\App\Http\Controllers\User\SessionController::class, 'destroy'])->name('sessions.destroy');
});

// Staff & Support Agent Routes (Accessible by Admin and Agent)
Route::middleware(['auth', 'staff'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/tickets', [\App\Http\Controllers\Admin\TicketController::class, 'index'])->name('tickets.index');
    Route::get('/tickets/{ticket}', [\App\Http\Controllers\Admin\TicketController::class, 'show'])->name('tickets.show');
    Route::post('/tickets/{ticket}/reply', [\App\Http\Controllers\Admin\TicketController::class, 'reply'])->name('tickets.reply');
    Route::patch('/tickets/{ticket}/status', [\App\Http\Controllers\Admin\TicketController::class, 'updateStatus'])->name('tickets.status');
    Route::patch('/tickets/{ticket}/assign', [\App\Http\Controllers\Admin\TicketController::class, 'assign'])->name('tickets.assign');
    Route::get('/tickets/{ticket}/attachments/{attachment}', [\App\Http\Controllers\Admin\TicketController::class, 'downloadAttachment'])->name('tickets.attachments.download');
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
    Route::post('/users/{user}/wallet/adjust', [AdminUserController::class, 'adjustWallet'])->name('users.wallet.adjust');

    Route::get('/numbers', [AdminPhoneNumberController::class, 'index'])->name('numbers.index');
    Route::post('/numbers', [AdminPhoneNumberController::class, 'store'])->name('numbers.store');
    Route::patch('/numbers/{number}', [AdminPhoneNumberController::class, 'update'])->name('numbers.update');
    Route::delete('/numbers/{number}', [AdminPhoneNumberController::class, 'destroy'])->name('numbers.destroy');
    Route::patch('/numbers/{number}/status', [AdminPhoneNumberController::class, 'updateStatus'])->name('numbers.status');
    Route::post('/numbers/{number}/assign', [AdminPhoneNumberController::class, 'assign'])->name('numbers.assign');
    Route::post('/numbers/{number}/release', [AdminPhoneNumberController::class, 'release'])->name('numbers.release');

    Route::get('/requests', [\App\Http\Controllers\Admin\NumberRequestController::class, 'index'])->name('requests.index');
    Route::post('/requests/{numberRequest}/approve', [\App\Http\Controllers\Admin\NumberRequestController::class, 'approve'])->name('requests.approve');
    Route::post('/requests/{numberRequest}/reject', [\App\Http\Controllers\Admin\NumberRequestController::class, 'reject'])->name('requests.reject');

    // Admin Phone Prefixes Management
    Route::get('/prefixes', [\App\Http\Controllers\Admin\PhonePrefixController::class, 'index'])->name('prefixes.index');
    Route::post('/prefixes', [\App\Http\Controllers\Admin\PhonePrefixController::class, 'store'])->name('prefixes.store');
    Route::patch('/prefixes/{prefix}', [\App\Http\Controllers\Admin\PhonePrefixController::class, 'update'])->name('prefixes.update');
    Route::delete('/prefixes/{prefix}', [\App\Http\Controllers\Admin\PhonePrefixController::class, 'destroy'])->name('prefixes.destroy');

    // Admin API Token Management & Oversight
    Route::get('/api-tokens', [\App\Http\Controllers\Admin\ApiTokenController::class, 'index'])->name('api-tokens.index');
    Route::post('/api-tokens', [\App\Http\Controllers\Admin\ApiTokenController::class, 'store'])->name('api-tokens.store');
    Route::post('/api-tokens/{token}/rotate', [\App\Http\Controllers\Admin\ApiTokenController::class, 'rotate'])->name('api-tokens.rotate');
    Route::post('/api-tokens/{token}/revoke', [\App\Http\Controllers\Admin\ApiTokenController::class, 'revoke'])->name('api-tokens.revoke');
    Route::delete('/api-tokens/{token}', [\App\Http\Controllers\Admin\ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');
    // Admin Messages Oversight & Live Monitoring
    Route::get('/messages', [\App\Http\Controllers\Admin\MessageController::class, 'index'])->name('messages.index');
    Route::get('/messages/conversation', [\App\Http\Controllers\Admin\MessageController::class, 'conversation'])->name('messages.conversation');
    Route::post('/messages/send', [\App\Http\Controllers\Admin\MessageController::class, 'send'])->name('messages.send');
    Route::get('/messages/poll', [\App\Http\Controllers\Admin\MessageController::class, 'poll'])->name('messages.poll');
    Route::delete('/messages/{message}', [\App\Http\Controllers\Admin\MessageController::class, 'destroy'])->name('messages.destroy');

    // Admin Audit Logs & Security Trails
    Route::get('/audit-logs', [\App\Http\Controllers\Admin\AuditLogController::class, 'index'])->name('audit-logs.index');
    Route::get('/audit-logs/{auditLog}', [\App\Http\Controllers\Admin\AuditLogController::class, 'show'])->name('audit-logs.show');

    // Admin Security Oversight & Incidents
    Route::get('/security/incidents', [\App\Http\Controllers\Admin\SecurityIncidentController::class, 'index'])->name('security.incidents.index');
    Route::post('/security/incidents/{incident}/resolve', [\App\Http\Controllers\Admin\SecurityIncidentController::class, 'resolve'])->name('security.incidents.resolve');
    Route::patch('/security/users/{user}/tier', [\App\Http\Controllers\Admin\SecurityIncidentController::class, 'updateUserTier'])->name('security.users.tier');

    // Admin Health & Monitoring Oversight Dashboard
    Route::get('/monitoring', [\App\Http\Controllers\Admin\MonitoringDashboardController::class, 'index'])->name('monitoring.index');
    Route::get('/monitoring/metrics', [\App\Http\Controllers\Admin\MonitoringDashboardController::class, 'metrics'])->name('monitoring.metrics');
    Route::post('/monitoring/errors/{errorLog}/resolve', [\App\Http\Controllers\Admin\MonitoringDashboardController::class, 'resolveError'])->name('monitoring.errors.resolve');

    // Admin Payment & Gateway Management
    Route::get('/payments', [\App\Http\Controllers\Admin\PaymentManagementController::class, 'index'])->name('payments.index');
    Route::get('/payments/gateways', [\App\Http\Controllers\Admin\PaymentManagementController::class, 'gateways'])->name('payments.gateways');
    Route::patch('/payments/gateways/{gateway}', [\App\Http\Controllers\Admin\PaymentManagementController::class, 'updateGateway'])->name('payments.gateways.update');
    Route::post('/payments/gateways/{gateway}/sync', [\App\Http\Controllers\Admin\PaymentManagementController::class, 'syncGateway'])->name('payments.gateways.sync');
    Route::get('/payments/{deposit}', [\App\Http\Controllers\Admin\PaymentManagementController::class, 'show'])->name('payments.show');
    Route::post('/payments/{deposit}/verify', [\App\Http\Controllers\Admin\PaymentManagementController::class, 'verify'])->name('payments.verify');
    Route::post('/payments/{deposit}/complete-manually', [\App\Http\Controllers\Admin\PaymentManagementController::class, 'completeManually'])->name('payments.complete-manually');
    Route::post('/payments/{deposit}/reject-manually', [\App\Http\Controllers\Admin\PaymentManagementController::class, 'rejectManually'])->name('payments.reject-manually');

    // Admin System & Authentication Settings Management
    Route::get('/settings/general', [\App\Http\Controllers\Admin\SettingController::class, 'general'])->name('settings.general');
    Route::post('/settings/general', [\App\Http\Controllers\Admin\SettingController::class, 'updateGeneral'])->name('settings.general.update');
    Route::post('/settings/org-pricing', [\App\Http\Controllers\Admin\SettingController::class, 'updateOrgPricing'])->name('settings.org_pricing.update');

    Route::get('/settings/auth', [\App\Http\Controllers\Admin\SettingController::class, 'auth'])->name('settings.auth');
    Route::post('/settings/auth', [\App\Http\Controllers\Admin\SettingController::class, 'updateAuth'])->name('settings.auth.update');

    Route::get('/settings/ldap', [\App\Http\Controllers\Admin\SettingController::class, 'ldap'])->name('settings.ldap');
    Route::post('/settings/ldap', [\App\Http\Controllers\Admin\SettingController::class, 'updateLdap'])->name('settings.ldap.update');
    Route::post('/settings/ldap/test', [\App\Http\Controllers\Admin\SettingController::class, 'testLdap'])->name('settings.ldap.test');

    Route::get('/settings/keycloak', [\App\Http\Controllers\Admin\SettingController::class, 'keycloak'])->name('settings.keycloak');
    Route::post('/settings/keycloak', [\App\Http\Controllers\Admin\SettingController::class, 'updateKeycloak'])->name('settings.keycloak.update');
    Route::post('/settings/keycloak/test', [\App\Http\Controllers\Admin\SettingController::class, 'testKeycloak'])->name('settings.keycloak.test');
});

require __DIR__.'/auth.php';

