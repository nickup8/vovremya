<?php

namespace App\Services\Booking;

use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Last-touch marketing attribution через server-side cookie.
 *
 * Cookie шифруется штатным Laravel EncryptCookies (в except он не входит),
 * HttpOnly, SameSite=lax. Значение — JSON-карта { master_id: { link_id, expires_at } },
 * что не даёт attribution мастера A примениться к мастеру B.
 *
 * Тариф здесь НЕ проверяется: публичный сбор attribution должен работать
 * даже для мастера на START (исторические active-ссылки после downgrade).
 */
class AttributionService
{
    private const COOKIE = 'booking_attribution';

    /**
     * Снять attribution на первом backend GET виджета.
     * Валидный tracked-переход заменяет источник и рестартит TTL.
     * Direct/invalid/foreign/disabled — не сбрасывают и не продлевают существующую attribution.
     */
    public function captureFromRequest(User $master, Request $request): void
    {
        $token = $request->query('ref');

        if (! is_string($token) || $token === '') {
            return; // Direct — ничего не трогаем.
        }

        $link = TrackingLink::query()
            ->where('token', $token)
            ->where('master_id', $master->id) // IDOR: чужой токен игнорируется.
            ->where('is_active', true)         // disabled токен не собирает attribution.
            ->first();

        if (! $link) {
            return; // invalid/foreign/disabled — существующую attribution не трогаем.
        }

        $days = $this->windowDays();

        $map = $this->readMap($request);
        $map[$master->id] = [
            'link_id' => $link->id,
            'expires_at' => now()->addDays($days)->getTimestamp(),
        ];

        $encoded = json_encode($map);
        if ($encoded === false) {
            return; // не удалось сериализовать — не трогаем существующую attribution.
        }

        Cookie::queue(cookie(
            name: self::COOKIE,
            value: $encoded,
            minutes: $days * 24 * 60,
            path: '/',
            domain: null,
            secure: $request->isSecure(),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        ));
    }

    /**
     * Резолв текущего валидного tracking_link_id для мастера в момент booking.
     * Заново валидирует сохранённую ссылку (принадлежность + активность):
     * если между click и booking ссылку отключили — источник не фиксируется.
     */
    public function resolveLinkId(User $master, Request $request): ?string
    {
        $entry = $this->readMap($request)[$master->id] ?? null;

        if (! is_array($entry)) {
            return null;
        }

        $expiresAt = $entry['expires_at'] ?? 0;
        if (! is_int($expiresAt) || $expiresAt < now()->getTimestamp()) {
            return null; // окно истекло.
        }

        $linkId = $entry['link_id'] ?? null;
        if (! is_string($linkId) || $linkId === '') {
            return null;
        }

        return TrackingLink::query()
            ->where('id', $linkId)
            ->where('master_id', $master->id)
            ->where('is_active', true)
            ->value('id');
    }

    private function windowDays(): int
    {
        return max(1, (int) config('booking.attribution_window_days', 7));
    }

    /**
     * @return array<string, mixed>
     */
    private function readMap(Request $request): array
    {
        $raw = $request->cookie(self::COOKIE);

        if (! is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
