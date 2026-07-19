<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Client;
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

    public function index(Request $request)
    {
        $master = auth()->user();

        if (! $master->is_master) {
            return redirect()->route('client.bookings')
                ->with('error', 'У вас нет профиля мастера.');
        }

        $appointments = $master->masterAppointments()
            ->with(['client', 'service'])
            ->whereBetween('start_time', [
                Carbon::now()->subWeeks(2)->startOfDay(),
                Carbon::now()->addWeeks(2)->endOfDay(),
            ])
            ->get()
            ->map(function (Appointment $a) use ($master) {
                $tz = $master->getTimezone();

                return [
                    'id' => $a->id,
                    'client_name' => $a->client?->name ?? 'Клиент не указан',
                    'client_phone' => $a->client?->phone,
                    'client_avatar_url' => $a->client?->avatar_url,
                    'service' => $a->service?->title ?? 'Услуга удалена',
                    'duration' => $a->service?->duration_minutes ?? 0,
                    'price' => (float) ($a->service?->price ?? 0),
                    'time' => $a->start_time->timezone($tz)->format('H:i'),
                    'date' => $a->start_time->timezone($tz)->format('Y-m-d'),
                    'status' => $a->status,
                ];
            });

        $blockedTimes = $master->blockedTimes()
            ->where('end_datetime', '>=', Carbon::now()->subWeeks(2)->startOfDay())
            ->where('start_datetime', '<=', Carbon::now()->addWeeks(2)->endOfDay())
            ->get()
            ->map(fn ($bt) => [
                'id' => $bt->id,
                'start_datetime' => $bt->start_datetime->toISOString(),
                'end_datetime' => $bt->end_datetime->toISOString(),
                'reason' => $bt->reason->value,
            ]);

        $workingHours = $master->workingHours()->get();

        $clients = Client::where('user_id', $master->id)
            ->get()
            ->map(fn (Client $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
            ]);

        $services = $master->services()
            ->get()
            ->map(fn (Service $s) => [
                'id' => $s->id,
                'title' => $s->title,
                'duration_minutes' => $s->duration_minutes,
                'price' => (float) $s->price,
            ]);

        $slotInterval = $master->slot_interval ?? 30;
        $timezone = $master->getTimezone();
        $timezoneConfirmed = $master->isTimezoneConfirmed();

        return Inertia::render('admin/calendar', [
            'appointments' => $appointments,
            'blockedTimes' => $blockedTimes,
            'workingHours' => $workingHours,
            'clients' => $clients,
            'services' => $services,
            'slotInterval' => $slotInterval,
            'timezone' => $timezone,
            'timezoneConfirmed' => $timezoneConfirmed,
            'prefillClientId' => $request->query('client_id'),
        ]);
    }

    public function store(Request $request)
    {
        $master = auth()->user();

        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'service_id' => 'required|exists:services,id',
            'date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'time' => 'required|date_format:H:i',
            'ignore_warnings' => 'sometimes|boolean',
            'confirm_outside_hours' => 'sometimes|boolean',
        ]);

        $client = Client::findOrFail($validated['client_id']);
        $this->authorize('view', $client);

        $service = Service::findOrFail($validated['service_id']);

        // Проверка принадлежности услуги текущему мастеру (защита от IDOR)
        if ($service->user_id !== $master->id) {
            abort(403, 'У вас нет прав на использование этой услуги.');
        }

        $result = $this->bookingService->createManualAppointment(
            $master,
            $service,
            $validated['date'],
            $validated['time'],
            $validated['ignore_warnings'] ?? false,
            $validated['confirm_outside_hours'] ?? false,
            $validated['client_id'],
        );

        if (! $result['success']) {
            $errorMap = [
                'break_intersection' => 'lunch_intersection',
                'outside_working_hours' => 'outside_working_hours',
            ];
            $errorKey = $errorMap[$result['error']] ?? 'time';

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
        $this->authorize('update', $appointment);

        $validated = $request->validate([
            'status' => 'sometimes|in:booked,pending_payment,prepaid,no_show,paid,cancelled',
            'start_time' => 'sometimes|date',
            'ignore_warnings' => 'sometimes|boolean',
            'confirm_outside_hours' => 'sometimes|boolean',
        ]);

        if (! isset($validated['status']) && ! isset($validated['start_time'])) {
            return back()->withErrors([
                'status' => 'Необходимо указать статус или новое время.',
            ]);
        }

        if (isset($validated['status'])) {
            $newStatus = AppointmentStatus::from($validated['status']);

            if (! $appointment->status->canTransitionTo($newStatus)) {
                return response()->json([
                    'error' => 'invalid_transition',
                    'message' => "Невозможно перевести запись из «{$appointment->status->label()}» в «{$newStatus->label()}».",
                ], 422);
            }

            $this->bookingService->updateStatus($appointment, $newStatus);
            unset($validated['status']);
        }

        if (isset($validated['start_time'])) {
            $tz = $appointment->master->getTimezone();
            $newDateTime = Carbon::parse($validated['start_time'], $tz);
            $newDate = $newDateTime->format('Y-m-d');
            $newTime = $newDateTime->format('H:i');

            $result = $this->bookingService->rescheduleAppointment(
                $appointment,
                $newDate,
                $newTime,
                $validated['ignore_warnings'] ?? false,
                $validated['confirm_outside_hours'] ?? false,
            );

            if (! $result['success']) {
                $errorMap = [
                    'break_intersection' => 'lunch_intersection',
                    'outside_working_hours' => 'outside_working_hours',
                ];
                $errorKey = $errorMap[$result['error']] ?? 'time';

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
            $dbFields = array_filter(
                $validated,
                fn ($key) => in_array($key, $appointment->getFillable()),
                ARRAY_FILTER_USE_KEY
            );

            if (! empty($dbFields)) {
                $appointment->update(
                    array_filter($dbFields, fn ($v) => $v !== null)
                );
            }
        }

        return back();
    }
}
