<?php

namespace App\Exceptions;

class ConsentIdempotencyCollisionException extends \DomainException
{
    public function __construct(string $idempotencyKey, string $existingAction, string $requestedAction)
    {
        parent::__construct(
            "Idempotency key [{$idempotencyKey}] already exists with action [{$existingAction}], cannot create [{$requestedAction}]."
        );
    }
}
