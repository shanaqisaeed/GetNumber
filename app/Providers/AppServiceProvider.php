<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            \App\Contracts\PhoneNumberAllocationServiceInterface::class,
            \App\Services\PhoneNumberAllocationService::class
        );

        $this->app->singleton(
            \App\Contracts\NumberRequestServiceInterface::class,
            \App\Services\NumberRequestService::class
        );

        $this->app->singleton(
            \App\Contracts\ApiTokenServiceInterface::class,
            \App\Services\ApiTokenService::class
        );

        $this->app->singleton(
            \App\Contracts\MessageServiceInterface::class,
            \App\Services\MessageService::class
        );

        $this->app->singleton(
            \App\Contracts\UnreadCountServiceInterface::class,
            \App\Services\UnreadCountService::class
        );

        $this->app->singleton(
            \App\Contracts\MessageArchiveServiceInterface::class,
            \App\Services\MessageArchiveService::class
        );

        $this->app->singleton(
            \App\Contracts\TicketServiceInterface::class,
            \App\Services\TicketService::class
        );

        $this->app->singleton(
            \App\Contracts\LedgerServiceInterface::class,
            \App\Services\LedgerService::class
        );

        $this->app->singleton(
            \App\Contracts\FinancialReconciliationServiceInterface::class,
            \App\Services\FinancialReconciliationService::class
        );

        $this->app->singleton(
            \App\Contracts\BlockchainWatcherServiceInterface::class,
            \App\Services\BlockchainWatcherService::class
        );

        $this->app->singleton(
            \App\Contracts\WalletServiceInterface::class,
            \App\Services\WalletService::class
        );

        $this->app->singleton(
            \App\Services\Gateways\PaymentGatewayManager::class
        );

        $this->app->singleton(
            \App\Contracts\PaymentServiceInterface::class,
            \App\Services\PaymentService::class
        );

        $this->app->singleton(
            \App\Contracts\SystemCacheServiceInterface::class,
            \App\Services\Cache\SystemCacheService::class
        );

        $this->app->singleton(\App\Services\Auth\LdapAuthService::class);
        $this->app->singleton(\App\Services\Auth\KeycloakAuthService::class);
        $this->app->singleton(\App\Services\Auth\IdentityAuthService::class);

        $this->app->singleton(
            \App\Contracts\Services\BusinessRuleEngineInterface::class,
            \App\Services\Catalog\BusinessRuleEngine::class
        );

        $this->app->singleton(
            \App\Contracts\Services\ServiceRegistryInterface::class,
            \App\Services\Catalog\ServiceRegistry::class
        );

        $this->app->singleton(\App\Services\Catalog\ServicePurchaseOrchestrator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \App\Services\Mail\MailConfigService::applySettings();

        Vite::prefetch(concurrency: 3);

        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Auth\Events\Registered::class,
            \Illuminate\Auth\Listeners\SendEmailVerificationNotification::class
        );

        \Illuminate\Support\Facades\Event::listen(
            \App\Events\MessageReceived::class,
            \App\Listeners\SendWebPushOnMessageReceived::class
        );

        \Illuminate\Support\Facades\RateLimiter::for('api-token', function (\Illuminate\Http\Request $request) {
            /** @var \App\Models\ApiToken|null $token */
            $token = $request->attributes->get('api_token');
            $key = $token ? 'token:' . $token->id : $request->ip();

            return \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by($key)->response(function () {
                return response()->json([
                    'success' => false,
                    'message' => __('system.rate_limit_exceeded'),
                    'code' => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            });
        });
    }
}