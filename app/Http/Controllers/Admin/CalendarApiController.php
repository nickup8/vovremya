<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\BlockedTime;
use App\Models\MasterService;
use App\Services\Booking\AvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CalendarApiController extends Controller
{
    public function range(Request $request): JsonResponse
    {
        $master = auth()->user();

        if (! $master->role->canManageTeam() && ! $master->is_master) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'start' => 'required|date_format:Y-m-d',
            'end' => 'required|date_format:Y-m-d',
        ]);

        $isAdminOrOwner = $master->role->canManageTeam();

        if ($isAdminOrOwner) {
            $masterIds = $master->workspace
                ? $master->workspace->users()->where('is_master', true)->pluck('id')
                : collect([$master->id]);
        } else {
            $masterIds = collect([$master->id]);
        }

        $tz = $master->getTimezone();

        $utcStart = Carbon::parse($validated['start'], $tz)->startOfDay()->utc();
        $utcEnd = Carbon::parse($validated['end'], $tz)->endOfDay()->utc();

        $appointments = Appointment::whereIn('master_id', $masterIds)
            ->with(['client', 'master'])
            ->whereBetween('start_time', [$utcStart, $utcEnd])
            ->where(function ($q) {
                $q->whereNotNull('client_id')
                  ->orWhereNotNull('source');
            })
            ->get()
            ->map(function (Appointment $a) {
                $tz = $a->master->getTimezone();

                return [
                    'id' => $a->id,
                    'client_name' => $a->client?->name ?? 'Клиент не указан',
                    'client_phone' => $a->client?->phone,
                    'client_avatar_url' => $a->client?->avatar_url,
                    'service' => $a->display_name,
                    'duration' => $a->display_duration,
                    'price' => $a->display_price,
                    'time' => $a->start_time->timezone($tz)->format('H:i'),
                    'date' => $a->start_time->timezone($tz)->format('Y-m-d'),
                    'status' => $a->status,
                    'master_id' => $a->master_id,
                    'master_name' => $a->master?->name ?? 'Мастер',
                    'client_confirmed_at' => $a->client_confirmed_at?->toIso8601String(),
                    'reminder_24h_sent_at' => $a->reminder_24h_sent_at?->toIso8601String(),
                ];
            });

        $blockedTimes = BlockedTime::whereIn('user_id', $masterIds)
            ->where('end_datetime', '>=', $utcStart)
            ->where('start_datetime', '<=', $utcEnd)
            ->with('user')
            ->get()
            ->map(fn ($bt) => [
                'id' => $bt->id,
                'date' => $bt->start_datetime->timezone($bt->user->getTimezone())->format('Y-m-d'),
                'end_date' => $bt->end_datetime->timezone($bt->user->getTimezone())->format('Y-m-d'),
                'start_time' => $bt->start_datetime->timezone($bt->user->getTimezone())->format('H:i'),
                'end_time' => $bt->end_datetime->timezone($bt->user->getTimezone())->format('H:i'),
                'reason' => $bt->reason->value,
                'user_id' => $bt->user_id,
            ]);

        return response()->json([
            'appointments' => $appointments,
            'blockedTimes' => $blockedTimes,
        ]);
    }

    public function availableSlots(Request $request, AvailabilityService $availability): JsonResponse
    {
        $master = auth()->user();

        if (! $master->role->canManageTeam() && ! $master->is_master) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'service_id' => 'nullable|exists:master_service,id',
        ]);

        if (! empty($validated['service_id'])) {
            $masterService = MasterService::with('catalog')->find($validated['service_id']);

            if (! $masterService) {
                return response()->json(['error' => 'Услуга не найдена.'], 422);
            }

            if (! $masterService->catalog || ! $masterService->catalog->is_active) {
                return response()->json(['error' => 'Эта услуга больше недоступна.'], 422);
            }

            $workspaceMasterIds = $master->workspace
                ? $master->workspace->users()->pluck('id')->all()
                : [$master->id];

            if (! in_array($masterService->master_id, $workspaceMasterIds, true)) {
                return response()->json(['error' => 'Forbidden'], 403);
            }

            $targetMaster = $masterService->master;
            $serviceDuration = $masterService->effective_duration;
            $date = Carbon::parse($validated['date'], $targetMaster->getTimezone());

            $freeSlots = $availability->getAvailableSlots($targetMaster, $date, $serviceDuration);
            $outsideSlots = $availability->getOutsideSlots($targetMaster, $date, $serviceDuration);
        } else {
            $targetMaster = $master;
            $date = Carbon::parse($validated['date'], $targetMaster->getTimezone());

            $result = $availability->getPotentialStartSlots($targetMaster, $date);
            $freeSlots = $result['freeSlots'];
            $outsideSlots = $result['outsideSlots'];
        }

        return response()->json([
            'freeSlots' => $freeSlots,
            'outsideSlots' => $outsideSlots,
        ]);
    }
}
