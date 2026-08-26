<?php

namespace App\Services;

use App\Enums\SlotOpportunitySourceType;
use App\Enums\SlotOpportunityStatus;
use App\Models\MasterService;
use App\Models\SlotOpportunity;
use App\Models\User;
use App\Models\Workspace;
use DateTimeInterface;
use Illuminate\Support\Str;

class SlotOpportunityService
{
    /**
     * Create a SlotOpportunity from a freed appointment window.
     *
     * Returns null if start_time is in the past (no point creating an Open opportunity for a window that already passed).
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
        $this->validatePayload($duration, $startTime, $workspaceId, $masterId, $masterServiceId);

        // Idempotency: check if this origin_event_id already exists
        $existing = SlotOpportunity::where('origin_event_id', $originEventId)->first();

        if ($existing !== null) {
            $this->assertPayloadMatches($existing, $masterId, $masterServiceId, $startTime, $duration, $sourceType, $sourceAppointmentId, $chainId);

            return $existing;
        }

        // Past windows are silently ignored — no point retrying
        if ($startTime->isPast()) {
            return null;
        }

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
    }

    private function validatePayload(
        int $duration,
        DateTimeInterface $startTime,
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
        string $masterId,
        string $masterServiceId,
        DateTimeInterface $startTime,
        int $duration,
        SlotOpportunitySourceType $sourceType,
        ?string $sourceAppointmentId,
        ?string $chainId,
    ): void {
        $conflicts = [];

        if ($existing->master_id !== $masterId) {
            $conflicts[] = 'master_id';
        }

        if ($existing->master_service_id !== $masterServiceId) {
            $conflicts[] = 'master_service_id';
        }

        if ($existing->start_time->format('Y-m-d H:i:s') !== $startTime->format('Y-m-d H:i:s')) {
            $conflicts[] = 'start_time';
        }

        if ($existing->duration !== $duration) {
            $conflicts[] = 'duration';
        }

        if ($existing->source_type !== $sourceType) {
            $conflicts[] = 'source_type';
        }

        if ($existing->source_appointment_id !== $sourceAppointmentId) {
            $conflicts[] = 'source_appointment_id';
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
