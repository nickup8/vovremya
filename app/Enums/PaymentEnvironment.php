<?php

namespace App\Enums;

/**
 * Explicit payment environment snapshot stored on each PaymentAttempt.
 *
 * Never inferred from TerminalKey, base URL, APP_ENV or the provider name:
 * tbank reads billing.gateways.tbank.environment, mock is always demo.
 */
enum PaymentEnvironment: string
{
    case Demo = 'demo';
    case Live = 'live';
}
