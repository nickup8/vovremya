<?php

namespace App\Services\Auth;

readonly class VkPhoneNumberResult
{
    public function __construct(
        public string $userId,
        public string $phone,
    ) {}
}
