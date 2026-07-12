<?php

namespace App\Http\Controllers\Client;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\WorkingHour;
use App\Models\BlockedTime;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ClientBookingController extends Controller
{
    public function create(): Response
    {
        $client = Auth::guard('client')->user();
        $masterId = $client->user_id;

        $services = Service::where('user_id', $masterId)
            ->select('id', 'title', 'price', 'duration_minutes')
            ->get();

        return Inertia::render('client/book', [
            'services' => $services,
            'masterId' => $masterId,
        ]);
    }

    public function getSlots(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'required|date|after_or_equal:today',
            'service_id' => 'required|exists:services,id',
        ]);

        $client = Auth::guard('client')->user();
        $masterId = $client->user_id;
        $date = Carbon::parse($validated['date']);
        $service = Service::find($validated['service_id']);

        if (! $service || $service->user_id !== $masterId) {
            return response()->json(['error' => 'Услуга не найдена'], 404);
        }

        $master = $client->master;
        $slotInterval = $master->slot_interval ?? 30;
        $duration = $service->duration_minutes;

        // Get working hours for this day of week
        $dayOfWeek = $date->dayOfWeekIso; // 1=Mon, 7=Sun
        $workingHour = WorkingHour::where('user_id', $masterId)
            ->where('day_of_week', $dayOfWeek)
            ->where('is_working', true)
            ->first();

        if (! $workingHour) {
            return response()->json(['slots' => []]);
        }

        // Generate all possible slot starts
        $start = Carbon::parse($date->toDateString() . ' ' . $workingHour->start_time);
        $end = Carbon::parse($date->toDateString() . ' ' . $workingHour->end_time);

        // Subtract service duration from end to get last valid start
        $lastValidStart = $end->copy()->subMinutes($duration);

        $slots = [];
        $current = $start->copy();

        while ($current->lte($lastValidStart)) {
            $slots[] = $current->format('H:i');
            $current->addMinutes($slotInterval);
        }

        // Get existing appointments for this day
        $existingAppointments = Appointment::where('master_id', $masterId)
            ->whereDate('start_time', $date)
            ->whereIn('status', [
                AppointmentStatus::Booked,
                AppointmentStatus::PendingPayment,
                AppointmentStatus::Prepaid,
            ])
            ->get(['start_time', 'service_id']);

        // Get blocked times for this day
        $blockedTimes = BlockedTime::where('user_id', $masterId)
            ->where('start_datetime', '<=', $date->copy()->endOfDay())
            ->where('end_datetime', '>=', $date->copy()->startOfDay())
            ->get(['start_datetime', 'end_datetime']);

        // Get service durations for overlap checks
        $serviceDurations = Service::where('user_id', $masterId)
            ->pluck('duration_minutes', 'id');

        // Filter out occupied slots
        $availableSlots = array_filter($slots, function ($slotTime) use ($date, $duration, $existingAppointments, $blockedTimes, $serviceDurations, $workingHour) {
            $slotStart = Carbon::parse($date->toDateString() . ' ' . $slotTime);
            $slotEnd = $slotStart->copy()->addMinutes($duration);

            // Check break time
            if ($workingHour->hasBreak()) {
                $breakStart = Carbon::parse($date->toDateString() . ' ' . $workingHour->break_start_time);
                $breakEnd = Carbon::parse($date->toDateString() . ' ' . $workingHour->break_end_time);

                if ($slotStart->lt($breakEnd) && $slotEnd->gt($breakStart)) {
                    return false;
                }
            }

            // Check existing appointments
            foreach ($existingAppointments as $appt) {
                $apptDuration = $serviceDurations[$appt->service_id] ?? 30;
                $apptStart = $appt->start_time;
                $apptEnd = $apptStart->copy()->addMinutes($apptDuration);

                if ($slotStart->lt($apptEnd) && $slotEnd->gt($apptStart)) {
                    return false;
                }
            }

            // Check blocked times
            foreach ($blockedTimes as $bt) {
                if ($slotStart->lt($bt->end_datetime) && $slotEnd->gt($bt->start_datetime)) {
                    return false;
                }
            }

            return true;
        });

        return response()->json(['slots' => array_values($availableSlots)]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'service_id' => 'required|exists:services,id',
            'datetime' => 'required|date|after_or_equal:now',
        ]);

        $client = Auth::guard('client')->user();
        $masterId = $client->user_id;

        $service = Service::find($validated['service_id']);

        if (! $service || $service->user_id !== $masterId) {
            return back()->withErrors(['service_id' => 'Услуга не найдена']);
        }

        $startDateTime = Carbon::parse($validated['datetime']);

        // Double-check slot availability
        $existing = Appointment::where('master_id', $masterId)
            ->where('start_time', $startDateTime)
            ->whereIn('status', [
                AppointmentStatus::Booked,
                AppointmentStatus::PendingPayment,
                AppointmentStatus::Prepaid,
            ])
            ->exists();

        if ($existing) {
            return back()->withErrors(['datetime' => 'Это время уже занято. Выберите другое.']);
        }

        Appointment::create([
            'master_id' => $masterId,
            'client_id' => $client->id,
            'service_id' => $service->id,
            'start_time' => $startDateTime,
            'status' => AppointmentStatus::Booked,
            'provider' => 'client_self',
        ]);

        return redirect()->route('client.bookings')
            ->with('success', 'Запись создана! Ждём вас.');
    }
}
