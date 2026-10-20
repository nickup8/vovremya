<?php

namespace App\Services\Payment;

use App\Enums\PaymentEnvironment;

/**
 * Resolves the payment environment for a NEW PaymentAttempt (snapshot).
 *
 * - tbank: explicit config billing.gateways.tbank.environment
 *   (env TBANK_ENVIRONMENT, default demo). Only 'demo'/'live' are accepted;
 *   any other value resolves to demo — never live.
 * - mock: always demo.
 * - any other provider: demo (fail-safe).
 *
 * Never inferred from TerminalKey, base URL, APP_ENV or the provider name
 * beyond the two explicit rules above. The snapshot is written once at
 * attempt creation; a later config change never rewrites existing attempts.
 */
class PaymentEnvironmentResolver
{
    public function resolve(string $provider): PaymentEnvironment
    {
        if ($provider === 'tbank') {
            $configured = config('billing.gateways.tbank.environment', 'demo');

            if (is_string($configured)
                && in_array($configured, [PaymentEnvironment::Demo->value, PaymentEnvironment::Live->value], true)) {
                return PaymentEnvironment::from($configured);
            }

            // Invalid/missing config must never become live.
            return PaymentEnvironment::Demo;
        }

        return PaymentEnvironment::Demo;
    }
}
