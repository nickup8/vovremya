<?php

namespace App\Services\Avatars;

/**
 * Валидация путей аватаров: принадлежность объекта определяется только
 * по принадлежащему приложению диску и разрешённым avatar-префиксам,
 * никогда — по endpoint'у или произвольному URL.
 */
final class AvatarPath
{
    /**
     * Путь безопасен, если это обычный относительный путь без выхода
     * из корня диска (никаких "../", абсолютных и "backslash"-путей).
     */
    public static function isSafe(?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }

        if (str_contains($path, '\\') || str_contains($path, '..')) {
            return false;
        }

        return ! str_starts_with($path, '/');
    }

    /**
     * Путь лежит в разрешённом avatar-префиксе (и в целом безопасен).
     */
    public static function isAllowed(?string $path): bool
    {
        if (! self::isSafe($path)) {
            return false;
        }

        foreach (self::allowedPrefixes() as $prefix) {
            if (str_starts_with((string) $path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Диски, файлы которых принадлежат приложению.
     *
     * @return list<string>
     */
    public static function ownedDisks(): array
    {
        $disks = [];

        foreach ((array) config('avatars.owned_disks', []) as $disk) {
            if (is_string($disk) && $disk !== '') {
                $disks[] = $disk;
            }
        }

        return $disks;
    }

    /**
     * Путь файла внутри публичного локального хранилища, если URL —
     * собственный локальный вида /storage/..., иначе null.
     */
    public static function fromLegacyUrl(?string $url): ?string
    {
        $prefix = (string) config('avatars.legacy_url_prefix', '/storage/');

        if ($url === null || $prefix === '' || ! str_starts_with($url, $prefix)) {
            return null;
        }

        return substr($url, strlen($prefix));
    }

    /**
     * @return list<string>
     */
    private static function allowedPrefixes(): array
    {
        $prefixes = [];

        foreach ((array) config('avatars.allowed_prefixes', []) as $prefix) {
            if (is_string($prefix) && $prefix !== '') {
                $prefixes[] = $prefix;
            }
        }

        return $prefixes;
    }
}
