<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\BlockedTime;
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

        $utcStart = Carbon::parse($validated['start'])->startOfDay()->timezone('UTC');
        $utcEnd = Carbon::parse($validated['end'])->endOfDay()->timezone('UTC');

        $appointments = Appointment::whereIn('master_id', $masterIds)
            ->with(['client', 'service', 'master'])
            ->whereBetween('start_time', [$utcStart, $utcEnd])
            ->whereNotNull('client_id')
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
}
