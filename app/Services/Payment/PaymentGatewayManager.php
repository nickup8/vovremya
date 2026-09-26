<?php

namespace App\Services\Payment;

use Illuminate\Support\Manager;

class PaymentGatewayManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return config('billing.default_gateway', 'mock');
    }

    /**
     * Get the default gateway for checkout.
     */
    public function getDefault(): PaymentGatewayInterface
    {
        return $this->driver();
    }

    /**
     * Get gateway by provider name for webhook/reconciliation.
     */
    public function getGateway(string $provider): PaymentGatewayInterface
    {
        if (! $this->has($provider)) {
            throw new \InvalidArgumentException("Unknown payment gateway: {$provider}");
        }

        return $this->driver($provider);
    }

    /**
     * Check if a gateway is registered for the given provider.
     */
    public function hasGateway(string $provider): bool
    {
        $gateways = config('billing.gateways', []);

        return isset($this->customCreators[$provider]) || array_key_exists($provider, $gateways);
    }

    /**
     * Create mock gateway driver.
     */
    protected function createMockDriver(): PaymentGatewayInterface
    {
        return new MockPaymentGateway();
    }

    /**
     * Create a gateway instance from config.
     */
    public function create(array $config): PaymentGatewayInterface
    {
        $driver = $config['driver'] ?? 'mock';

        return match ($driver) {
            'mock' => $this->createMockDriver(),
            // Future: 'tbank' => $this->createTbankDriver($config),
            default => throw new \InvalidArgumentException("Unknown gateway driver: {$driver}"),
        };
    }
}
