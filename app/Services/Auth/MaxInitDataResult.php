<?php

namespace App\Services\Auth;

readonly class MaxInitDataResult
{
    public function __construct(
        public string $userId,
        public int $authDate,
        public ?string $startParam,
        public ?string $chatId,
        public array $raw,
    ) {}
}
