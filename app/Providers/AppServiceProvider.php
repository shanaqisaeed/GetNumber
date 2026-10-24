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
            \App\Contracts\TicketServiceInterface::class,
            \App\Services\TicketService::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        \Illuminate\Support\Facades\RateLimiter::for('api-token', function (\Illuminate\Http\Request $request) {
            /** @var \App\Models\ApiToken|null $token */
            $token = $request->attributes->get('api_token');
            $key = $token ? 'token:' . $token->id : $request->ip();

            return \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by($key)->response(function () {
                return response()->json([
                    'success' => false,
                    'message' => 'تعداد درخواست‌های ارسالی بیش از سقف مجاز (Rate Limit) در دقیقه می‌باشد. لطفاً شکیبا باشید.',
                    'code' => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            });
        });
    }
}
