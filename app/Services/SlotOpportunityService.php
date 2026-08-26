<?php

namespace App\Services;

use App\Enums\SlotOpportunitySourceType;
use App\Enums\SlotOpportunityStatus;
use App\Models\MasterService;
use App\Models\SlotOpportunity;
use App\Models\User;
use App\Models\Workspace;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

class SlotOpportunityService
{
    /**
     * Create a SlotOpportunity from a freed appointment window.
     *
     * Race-safe: handles concurrent inserts for the same origin_event_id
     * by catching the UNIQUE violation and returning the existing row.
     *
     * Returns null only for genuinely new events with past start_time.
     *
     * @return SlotOpportunity|null
     */
    public function createFromFreedWindow(
        string $originEventId,
        ?string $chainId,
        string $workspaceId,
        string $masterId,
        string $masterServiceId,
        ?string $sourceAppointmentId,
        SlotOpportunitySourceType $sourceType,
        DateTimeInterface $startTime,
        int $duration,
    ): ?SlotOpportunity {
        // 1. Idempotency lookup — always first
        $existing = SlotOpportunity::where('origin_event_id', $originEventId)->first();

        if ($existing !== null) {
            $this->assertPayloadMatches($existing, $workspaceId, $masterId, $masterServiceId, $sourceAppointmentId, $sourceType, $startTime, $duration, $chainId);

            return $existing;
        }

        // 2. Validate payload for new event
        $this->validatePayload($duration, $workspaceId, $masterId, $masterServiceId);

        // 3. Past windows for NEW events are silently ignored
        if ($startTime->isPast()) {
            return null;
        }

        // 4. Attempt insert, handle concurrent race
        try {
            return SlotOpportunity::create([
                'origin_event_id' => $originEventId,
                'chain_id' => $chainId ?? (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'master_id' => $masterId,
                'master_service_id' => $masterServiceId,
                'source_appointment_id' => $sourceAppointmentId,
                'source_type' => $sourceType,
                'status' => SlotOpportunityStatus::Open,
                'start_time' => $startTime,
                'duration' => $duration,
            ]);
        } catch (QueryException $e) {
            if ($e->getPrevious()?->getCode() !== '23505') {
                throw $e;
            }

            // UNIQUE violation on origin_event_id — concurrent insert won
            $existing = SlotOpportunity::where('origin_event_id', $originEventId)->first();

            if ($existing === null) {
                // Should not happen, but rethrow original if row mysteriously missing
                throw $e;
            }

            $this->assertPayloadMatches($existing, $workspaceId, $masterId, $masterServiceId, $sourceAppointmentId, $sourceType, $startTime, $duration, $chainId);

            return $existing;
        }
    }

    private function validatePayload(
        int $duration,
        string $workspaceId,
        string $masterId,
        string $masterServiceId,
    ): void {
        if ($duration <= 0) {
            throw new \DomainException('Duration must be greater than zero.');
        }

        $workspace = Workspace::find($workspaceId);
        if ($workspace === null) {
            throw new \DomainException('Workspace not found.');
        }

        $master = User::find($masterId);
        if ($master === null) {
            throw new \DomainException('Master not found.');
        }

        if ($master->workspace_id !== $workspaceId) {
            throw new \DomainException('Master does not belong to this workspace.');
        }

        $masterService = MasterService::find($masterServiceId);
        if ($masterService === null) {
            throw new \DomainException('MasterService not found.');
        }

        if ($masterService->master_id !== $masterId) {
            throw new \DomainException('MasterService does not belong to this master.');
        }
    }

    private function assertPayloadMatches(
        SlotOpportunity $existing,
        string $workspaceId,
        string $masterId,
        string $masterServiceId,
        ?string $sourceAppointmentId,
        SlotOpportunitySourceType $sourceType,
        DateTimeInterface $startTime,
        int $duration,
        ?string $chainId,
    ): void {
        $conflicts = [];

        if ($existing->workspace_id !== $workspaceId) {
            $conflicts[] = 'workspace_id';
        }

        if ($existing->master_id !== $masterId) {
            $conflicts[] = 'master_id';
        }

        if ($existing->master_service_id !== $masterServiceId) {
            $conflicts[] = 'master_service_id';
        }

        if ($existing->source_appointment_id !== $sourceAppointmentId) {
            $conflicts[] = 'source_appointment_id';
        }

        if ($existing->source_type !== $sourceType) {
            $conflicts[] = 'source_type';
        }

        if ($existing->start_time->format('Y-m-d H:i:s') !== $startTime->format('Y-m-d H:i:s')) {
            $conflicts[] = 'start_time';
        }

        if ($existing->duration !== $duration) {
            $conflicts[] = 'duration';
        }

        if ($chainId !== null && $existing->chain_id !== $chainId) {
            $conflicts[] = 'chain_id';
        }

        if (! empty($conflicts)) {
            throw new \DomainException(
                'Origin event already exists with different payload. Conflicting fields: ' . implode(', ', $conflicts)
            );
        }
    }
}
