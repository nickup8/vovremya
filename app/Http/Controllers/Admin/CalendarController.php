<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Service;
use App\Services\Booking\BookingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class CalendarController extends Controller
{
    public function __construct(
        private BookingService $bookingService,
    ) {}

    public function index()
    {
        $master = auth()->user();

        if (! $master->is_master) {
            return redirect()->route('client.bookings')
                ->with('error', 'У вас нет профиля мастера.');
        }

        $appointments = $master->masterAppointments()
            ->with(['client', 'service'])
            ->get()
            ->map(fn (Appointment $a) => [
                'id' => $a->id,
                'client_name' => $a->client->name,
                'client_phone' => $a->client->phone,
                'service' => $a->service->title,
                'duration' => $a->service->duration_minutes,
                'price' => (float) $a->service->price,
                'time' => $a->start_time->format('H:i'),
                'date' => $a->start_time->format('Y-m-d'),
                'status' => $a->status,
            ]);

        $blockedTimes = $master->blockedTimes()
            ->get()
            ->map(fn ($bt) => [
                'id' => $bt->id,
                'start_datetime' => $bt->start_datetime->toISOString(),
                'end_datetime' => $bt->end_datetime->toISOString(),
                'reason' => $bt->reason->value,
            ]);

        $workingHours = $master->workingHours()->get();

        return Inertia::render('admin/calendar', [
            'appointments' => $appointments,
            'blockedTimes' => $blockedTimes,
            'workingHours' => $workingHours,
        ]);
    }

    public function store(Request $request)
    {
        $master = auth()->user();

        $validated = $request->validate([
            'service_id' => 'required|exists:services,id',
            'date' => 'required|date_format:Y-m-d',
            'time' => 'required|date_format:H:i',
            'ignore_warnings' => 'sometimes|boolean',
        ]);

        $service = Service::findOrFail($validated['service_id']);

        $result = $this->bookingService->createManualAppointment(
            $master,
            $service,
            $validated['date'],
            $validated['time'],
            $validated['ignore_warnings'] ?? false,
        );

        if (! $result['success']) {
            $errorKey = $result['error'] === 'break_intersection' ? 'lunch_intersection' : 'time';

            if ($request->header('X-Inertia')) {
                return back()->withErrors([
                    $errorKey => $result['message'],
                ])->withInput();
            }

            return response()->json([
                'error' => $result['error'],
                'message' => $result['message'],
                'break_info' => $result['break_info'] ?? null,
            ], 422);
        }

        return back()->with('success', 'Запись создана');
    }

    public function updateStatus(Request $request, Appointment $appointment)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending_client,confirmed,completed,no_show,cancelled',
            'start_time' => 'sometimes|date',
            'ignore_warnings' => 'sometimes|boolean',
        ]);

        if (isset($validated['status'])) {
            $this->bookingService->updateStatus(
                $appointment,
                AppointmentStatus::from($validated['status'])
            );
            unset($validated['status']);
        }

        if (isset($validated['start_time'])) {
            $newDateTime = Carbon::parse($validated['start_time']);
            $newDate = $newDateTime->format('Y-m-d');
            $newTime = $newDateTime->format('H:i');

            $result = $this->bookingService->rescheduleAppointment(
                $appointment,
                $newDate,
                $newTime,
                $validated['ignore_warnings'] ?? false,
            );

            if (! $result['success']) {
                $errorKey = $result['error'] === 'break_intersection' ? 'lunch_intersection' : 'time';

                if ($request->header('X-Inertia')) {
                    return back()->withErrors([
                        $errorKey => $result['message'],
                    ])->withInput();
                }

                return response()->json([
                    'error' => $result['error'],
                    'message' => $result['message'],
                    'break_info' => $result['break_info'] ?? null,
                ], 422);
            }

            unset($validated['start_time']);
        }

        if (! empty($validated)) {
            $appointment->update(
                array_filter($validated, fn ($v) => $v !== null)
            );
        }

        return back();
    }
}
