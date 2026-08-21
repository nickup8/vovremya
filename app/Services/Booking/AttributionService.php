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
     * Установить 7-day last-touch attribution для валидной active ссылки.
     * Caller обязан предварительно найти TrackingLink и проверить is_active.
     */
    public function captureByToken(User $master, TrackingLink $link, Request $request): void
    {
        $days = $this->windowDays();

        $map = $this->readMap($request);
        $map[$master->id] = [
            'link_id' => $link->id,
            'expires_at' => now()->addDays($days)->getTimestamp(),
        ];

        $encoded = json_encode($map);
        if ($encoded === false) {
            return;
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
