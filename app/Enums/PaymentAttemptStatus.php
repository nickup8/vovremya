<?php

namespace App\Enums;

enum PaymentAttemptStatus: string
{
    case Created = 'created';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case FailedRetryable = 'failed_retryable';
    case FailedTerminal = 'failed_terminal';
    case Unknown = 'unknown';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
}
