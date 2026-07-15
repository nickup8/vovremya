<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait SearchableByProvider
{
    public function scopeByTelegramId(Builder $query, string $telegramId): Builder
    {
        return $query->where('telegram_id', $telegramId);
    }

    public function scopeByMaxId(Builder $query, string $maxId): Builder
    {
        return $query->where('max_id', $maxId);
    }

    public static function findByTelegramId(string $telegramId): ?static
    {
        return static::where('telegram_id', $telegramId)->first();
    }

    public static function findByMaxId(string $maxId): ?static
    {
        return static::where('max_id', $maxId)->first();
    }
}
