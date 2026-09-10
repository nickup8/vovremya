<?php

namespace App\Services\Auth;

readonly class VkLaunchParamsResult
{
    public function __construct(
        public string $userId,
        public string $appId,
        public ?int $timestamp,
        public array $raw,
    ) {}
}
