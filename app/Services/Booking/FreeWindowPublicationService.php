<?php

namespace App\Services\Booking;

use App\Models\FreeWindowPublication;
use App\Models\MasterService;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class FreeWindowPublicationService
{
    /**
     * Build canonical content hash for idempotent reuse.
     */
    public function buildContentHash(
        User $master,
        string $mode,
        ?string $serviceId,
        string $dateFrom,
        string $dateTo,
        array $visibleDays,
    ): string {
        $data = [
            'master_id' => $master->id,
            'mode' => $mode,
            'service_id' => $serviceId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'days' => $this->canonicalDays($visibleDays, $mode),
        ];
        $canonical = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $canonical);
    }

    /**
     * Create a new publication or reuse an existing unexpired one with the same content hash.
     */
    public function createOrReuse(
        User $master,
        string $mode,
        ?string $serviceId,
        string $dateFrom,
        string $dateTo,
        array $visibleDays,
    ): FreeWindowPublication {
        $contentHash = $this->buildContentHash($master, $mode, $serviceId, $dateFrom, $dateTo, $visibleDays);

        // Try to reuse an existing unexpired publication with the same content
        $existing = FreeWindowPublication::where('master_id', $master->id)
            ->where('content_hash', $contentHash)
            ->where('expires_at', '>', now())
            ->first();

        if ($existing) {
            return $existing;
        }

        $tz = $master->getTimezone();
        $expiresAt = Carbon::parse($dateTo, $tz)->endOfDay()->utc();

        // Generate token with collision retry
        $token = $this->generateUniqueToken();

        return DB::transaction(function () use (
            $master, $mode, $serviceId, $dateFrom, $dateTo,
            $visibleDays, $contentHash, $token, $expiresAt,
        ) {
            return FreeWindowPublication::create([
                'master_id' => $master->id,
                'token' => $token,
                'content_hash' => $contentHash,
                'mode' => $mode,
                'master_service_id' => $serviceId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'payload' => ['days' => $visibleDays],
                'expires_at' => $expiresAt,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Resolve a publication token. Returns null if not found, expired, or master mismatch.
     */
    public function resolveForMaster(string $token, User $master): ?FreeWindowPublication
    {
        $pub = FreeWindowPublication::where('token', $token)
            ->where('master_id', $master->id)
            ->first();

        if (! $pub) {
            return null;
        }

        if ($pub->isExpired()) {
            return null;
        }

        return $pub;
    }

    /**
     * Filter real slots through publication constraints.
     *
     * Service mode: intersect real slots with snapshot starts.
     * All mode: keep slots whose [start, start+duration) fits inside a publication range.
     */
    public function filterSlots(
        FreeWindowPublication $pub,
        string $date,
        array $realSlots,
        int $effectiveDuration,
    ): array {
        $payloadDays = $pub->payload['days'] ?? [];
        $dayData = collect($payloadDays)->firstWhere('date', $date);

        if (! $dayData) {
            return [];
        }

        if ($pub->mode === 'service') {
            $snapshotStarts = $dayData['starts'] ?? [];
            return array_values(array_intersect($realSlots, $snapshotStarts));
        }

        // All mode: filter by range containment using explicit duration
        $ranges = $dayData['ranges'] ?? [];
        if (empty($ranges)) {
            return [];
        }

        $filtered = [];
        foreach ($realSlots as $slotStart) {
            $startMinutes = $this->timeToMinutes($slotStart);
            $endMinutes = $startMinutes + $effectiveDuration;

            foreach ($ranges as $range) {
                $rangeStart = $this->timeToMinutes($range['start']);
                $rangeEnd = $this->timeToMinutes($range['end']);

                if ($startMinutes >= $rangeStart && $endMinutes <= $rangeEnd) {
                    $filtered[] = $slotStart;
                    break;
                }
            }
        }

        return $filtered;
    }

    /**
     * Assert that a specific time is bookable within the publication.
     * Used in POST enforcement.
     */
    public function assertBookable(
        FreeWindowPublication $pub,
        string $date,
        string $time,
        int $durationMinutes,
    ): bool {
        $payloadDays = $pub->payload['days'] ?? [];
        $dayData = collect($payloadDays)->firstWhere('date', $date);

        if (! $dayData) {
            return false;
        }

        if ($pub->mode === 'service') {
            $snapshotStarts = $dayData['starts'] ?? [];
            return in_array($time, $snapshotStarts, true);
        }

        // All mode: check range containment
        $ranges = $dayData['ranges'] ?? [];
        $startMinutes = $this->timeToMinutes($time);
        $endMinutes = $startMinutes + $durationMinutes;

        foreach ($ranges as $range) {
            $rangeStart = $this->timeToMinutes($range['start']);
            $rangeEnd = $this->timeToMinutes($range['end']);

            if ($startMinutes >= $rangeStart && $endMinutes <= $rangeEnd) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the publication-aware booking URL.
     */
    public function buildPublicationUrl(FreeWindowPublication $pub): string
    {
        $master = $pub->master;
        $params = ['fw' => $pub->token];

        if ($pub->mode === 'service' && $pub->master_service_id) {
            $params['service_id'] = $pub->master_service_id;
        }

        return URL::route('booking.widget', array_merge(
            ['master' => $master->master_slug],
            $params,
        ));
    }

    private function generateUniqueToken(): string
    {
        $maxAttempts = 5;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $token = Str::random(random_int(24, 32));
            if (! FreeWindowPublication::where('token', $token)->exists()) {
                return $token;
            }
        }

        // Fallback: use a UUID-based token (collision impossible)
        return Str::uuid()->toString();
    }

    private function canonicalDays(array $days, string $mode): array
    {
        $result = [];
        foreach ($days as $day) {
            $entry = ['date' => $day['date']];
            if ($mode === 'service' && isset($day['starts'])) {
                $entry['starts'] = $day['starts'];
            } elseif ($mode === 'all' && isset($day['ranges'])) {
                $entry['ranges'] = $day['ranges'];
            }
            $result[] = $entry;
        }
        return $result;
    }

    private function timeToMinutes(string $time): int
    {
        $parts = explode(':', $time);
        return (int) $parts[0] * 60 + (int) ($parts[1] ?? 0);
    }
}
