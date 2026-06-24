<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class BookingWidgetController extends Controller
{
    public function show(string $slug, Request $request)
    {
        $master = User::where('master_slug', $slug)
            ->where('is_master', true)
            ->firstOrFail()
            ->load('services');

        $selectedServiceId = $request->query('service_id');
        $selectedDate = $request->query('date', Carbon::today()->toDateString());

        $availableSlots = $this->calculateAvailableSlots(
            $master,
            $selectedServiceId ? Service::find($selectedServiceId) : null,
            $selectedDate
        );

        return Inertia::render('booking/widget', [
            'master' => [
                'name' => $master->name,
                'specialty' => $master->specialty,
                'address' => $master->address,
                'avatar_url' => $master->avatar_url,
                'master_slug' => $master->master_slug,
            ],
            'services' => $master->services->map(fn ($s) => [
                'id' => $s->id,
                'title' => $s->title,
                'price' => (float) $s->price,
                'duration_minutes' => $s->duration_minutes,
            ]),
            'availableSlots' => $availableSlots,
            'selectedDate' => $selectedDate,
            'selectedServiceId' => $selectedServiceId ? (int) $selectedServiceId : null,
        ]);
    }

    public function store(Request $request, string $slug)
    {
        $master = User::where('master_slug', $slug)
            ->where('is_master', true)
            ->firstOrFail();

        $validated = $request->validate([
            'service_id' => 'required|exists:services,id',
            'date' => 'required|date_format:Y-m-d',
            'time' => 'required|date_format:H:i',
            'provider' => 'required|in:telegram,max',
        ]);

        $service = Service::findOrFail($validated['service_id']);

        $startDateTime = Carbon::parse($validated['date'].' '.$validated['time']);
        $endDateTime = $startDateTime->copy()->addMinutes($service->duration_minutes);

        $bookedEndTimes = Appointment::where('master_id', $master->id)
            ->whereIn('status', ['pending_client', 'confirmed'])
            ->whereDate('start_time', $validated['date'])
            ->with('service')
            ->get()
            ->map(fn (Appointment $a) => [
                'start' => $a->start_time,
                'end' => $a->start_time->copy()->addMinutes(
                    $a->service ? $a->service->duration_minutes : 60
                ),
            ]);

        $hasConflict = $bookedEndTimes->contains(
            fn (array $period) => $startDateTime->lt($period['end']) && $endDateTime->gt($period['start'])
        );

        if ($hasConflict) {
            return back()->withErrors([
                'time' => 'Этот слот уже занят. Выберите другое время.',
            ])->withInput();
        }

        $appointment = Appointment::create([
            'master_id' => $master->id,
            'client_id' => null,
            'service_id' => $service->id,
            'start_time' => $startDateTime,
            'status' => 'pending_client',
            'provider' => $validated['provider'],
        ]);

        $botLink = $validated['provider'] === 'telegram'
            ? 'https://t.me/vovremia_bot?start=book_'.$appointment->id
            : 'https://max.ru/bot/vovremia_bot?start=book_'.$appointment->id;

        return redirect()->away($botLink);
    }

    private function calculateAvailableSlots(User $master, ?Service $service, string $date): array
    {
        if (! $service) {
            return [];
        }

        $dayStart = Carbon::parse($date)->setTime(8, 0);
        $dayEnd = Carbon::parse($date)->setTime(21, 0);
        $duration = $service->duration_minutes;

        $existingAppointments = Appointment::where('master_id', $master->id)
            ->whereIn('status', ['pending_client', 'confirmed'])
            ->whereDate('start_time', $date)
            ->with('service')
            ->get();

        $bookedPeriods = $existingAppointments->map(fn (Appointment $a) => [
            'start' => $a->start_time,
            'end' => $a->start_time->copy()->addMinutes(
                $a->service ? $a->service->duration_minutes : 60
            ),
        ]);

        $slots = [];
        $slot = $dayStart->copy();

        while ($slot->copy()->addMinutes($duration)->lte($dayEnd)) {
            $slotEnd = $slot->copy()->addMinutes($duration);

            $isPast = $date === Carbon::today()->toDateString()
                && $slot->lt(Carbon::now());

            $hasOverlap = $bookedPeriods->contains(
                fn (array $period) => $slot->lt($period['end']) && $slotEnd->gt($period['start'])
            );

            if (! $isPast && ! $hasOverlap) {
                $slots[] = $slot->format('H:i');
            }

            $slot->addMinutes(30);
        }

        return $slots;
    }
}
