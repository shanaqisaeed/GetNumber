<?php

namespace App\Services\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\Models\PaymentGateway;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class PaymentGatewayManager
{
    /**
     * Map of driver identifiers to gateway adapter classes.
     */
    protected array $drivers = [
        'nobitex' => NobitexGateway::class,
        'tron_grid' => TronGridGateway::class,
        'mock' => MockPaymentGateway::class,
    ];

    /**
     * Instantiated gateway instances cache.
     */
    protected array $instances = [];

    /**
     * Resolve a gateway adapter by driver string.
     */
    public function driver(?string $driver = null): PaymentGatewayInterface
    {
        $resolvedDriver = $driver ?: $this->getDefaultDriver();

        if (isset($this->instances[$resolvedDriver])) {
            return $this->instances[$resolvedDriver];
        }

        if (! isset($this->drivers[$resolvedDriver])) {
            throw new InvalidArgumentException("درگاه پرداختی با شناسه «{$resolvedDriver}» تعریف نشده است.");
        }

        $gatewayModel = PaymentGateway::where('driver', $resolvedDriver)->first();
        $class = $this->drivers[$resolvedDriver];

        return $this->instances[$resolvedDriver] = new $class($gatewayModel);
    }

    /**
     * Resolve gateway adapter for a specific database model instance.
     */
    public function forModel(PaymentGateway $model): PaymentGatewayInterface
    {
        $driver = $model->driver;

        if (! isset($this->drivers[$driver])) {
            throw new InvalidArgumentException("درایور ناشناخته: «{$driver}»");
        }

        $class = $this->drivers[$driver];
        return new $class($model);
    }

    /**
     * Get the default gateway instance.
     */
    public function default(): PaymentGatewayInterface
    {
        return $this->driver($this->getDefaultDriver());
    }

    /**
     * Get all active gateways from database.
     */
    public function getActiveGateways(): Collection
    {
        return PaymentGateway::active()->get();
    }

    /**
     * Determine default driver from database or fallback.
     */
    public function getDefaultDriver(): string
    {
        $default = PaymentGateway::default()->first();

        if ($default && isset($this->drivers[$default->driver])) {
            return $default->driver;
        }

        return 'nobitex';
    }

    /**
     * Register a custom gateway driver.
     */
    public function registerDriver(string $driver, string $class): void
    {
        $this->drivers[$driver] = $class;
    }
}

