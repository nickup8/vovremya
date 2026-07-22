<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SlugService
{
    /**
     * Гарантированно уникальный slug для мастера.
     * Приоритет: username → ФИО → рандом.
     */
    public function generate(?string $username, ?string $firstName, ?string $lastName): string
    {
        $base = $this->resolveBase($username, $firstName, $lastName);

        return DB::transaction(function () use ($base) {
            $slug = $this->normalize($base);

            $attempts = 0;
            while (User::where('master_slug', $slug)->lockForUpdate()->exists()) {
                $attempts++;
                if ($attempts > 5) {
                    $slug = Str::lower(Str::random(8));
                } else {
                    $slug = $this->normalize($base) . '-' . Str::lower(Str::random(5));
                }
            }

            return $slug;
        });
    }

    private function resolveBase(?string $username, ?string $firstName, ?string $lastName): string
    {
        if ($username !== null && $username !== '') {
            return $username;
        }

        $fullName = trim(($firstName ?? '') . ' ' . ($lastName ?? ''));

        if ($fullName !== '') {
            return $fullName;
        }

        return Str::random(8);
    }

    private function normalize(string $value): string
    {
        $slug = Str::slug($value);

        if ($slug === '') {
            return Str::lower(Str::random(8));
        }

        return $slug;
    }
}
