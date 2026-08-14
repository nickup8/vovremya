<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\BlockedTime;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\User;
use App\Models\WorkingHour;
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

        if (! $master->role->canManageTeam() && ! $master->is_master) {
            return redirect()->route('client.bookings')
                ->with('error', 'У вас нет доступа к календарю.');
        }

        $validated = $request->validate([
            'start' => 'nullable|date_format:Y-m-d',
            'end' => 'nullable|date_format:Y-m-d',
        ]);

        $rangeStart = isset($validated['start'])
            ? Carbon::parse($validated['start'])->startOfDay()
            : Carbon::now()->subWeeks(3)->startOfDay();
        $rangeEnd = isset($validated['end'])
            ? Carbon::parse($validated['end'])->endOfDay()
            : Carbon::now()->addWeeks(3)->endOfDay();

        $isAdminOrOwner = $master->role->canManageTeam();

        if ($isAdminOrOwner) {
            $masterIds = $master->workspace
                ? $master->workspace->users()->where('is_master', true)->pluck('id')
                : collect([$master->id]);

            $appointments = Appointment::whereIn('master_id', $masterIds)
                ->with(['client', 'master', 'masterService.catalog'])
                ->whereBetween('start_time', [
                    $rangeStart,
                    $rangeEnd,
                ])
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
                        'client_confirmed_at' => $a->client_confirmed_at?->toIso8601String(),
                        'reminder_24h_sent_at' => $a->reminder_24h_sent_at?->toIso8601String(),
                    ];
                });

            $blockedTimes = BlockedTime::whereIn('user_id', $masterIds)
                ->where('end_datetime', '>=', $rangeStart)
                ->where('start_datetime', '<=', $rangeEnd)
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

            $workingHours = WorkingHour::whereIn('user_id', $masterIds)->get();

            $clients = Client::forWorkspaceOrMaster($master)
                ->get()
                ->map(fn (Client $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'phone' => $c->phone,
                ]);

            $services = MasterService::whereIn('master_id', $masterIds)
                ->where('is_active', true)
                ->with('catalog')
                ->get()
                ->map(fn (MasterService $s) => [
                    'id' => $s->id,
                    'title' => $s->catalog?->title ?? '—',
                    'duration_minutes' => $s->effective_duration,
                    'price' => (float) $s->effective_price,
                    'master_id' => $s->master_id,
                ])
                ->filter(fn ($s) => $s['title'] !== '—');

            $slotInterval = $master->slot_interval ?? 30;
            $timezone = $master->getTimezone();
            $timezoneConfirmed = $master->isTimezoneConfirmed();
        } else {
            $tz = $master->getTimezone();

            $appointments = $master->masterAppointments()
                ->with(['client', 'masterService.catalog'])
                ->whereBetween('start_time', [
                    $rangeStart,
                    $rangeEnd,
                ])
                ->whereNotNull('client_id')
                ->get()
                ->map(function (Appointment $a) use ($tz) {
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
                        'client_confirmed_at' => $a->client_confirmed_at?->toIso8601String(),
                        'reminder_24h_sent_at' => $a->reminder_24h_sent_at?->toIso8601String(),
                    ];
                });

            $blockedTimes = $master->blockedTimes()
                ->where('end_datetime', '>=', $rangeStart)
                ->where('start_datetime', '<=', $rangeEnd)
                ->get()
                ->map(fn ($bt) => [
                    'id' => $bt->id,
                    'date' => $bt->start_datetime->timezone($tz)->format('Y-m-d'),
                    'end_date' => $bt->end_datetime->timezone($tz)->format('Y-m-d'),
                    'start_time' => $bt->start_datetime->timezone($tz)->format('H:i'),
                    'end_time' => $bt->end_datetime->timezone($tz)->format('H:i'),
                    'reason' => $bt->reason->value,
                ]);

            if ($master->is_master && $master->workingHours()->doesntExist()) {
                $master->createDefaultWorkingHours();
            }

            $workingHours = $master->workingHours()->get();

            $clients = Client::forWorkspaceOrMaster($master)
                ->get()
                ->map(fn (Client $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'phone' => $c->phone,
                ]);

            $services = MasterService::where('master_id', $master->id)
                ->where('is_active', true)
                ->with('catalog')
                ->get()
                ->map(fn (MasterService $s) => [
                    'id' => $s->id,
                    'title' => $s->catalog?->title ?? '—',
                    'duration_minutes' => $s->effective_duration,
                    'price' => (float) $s->effective_price,
                    'master_id' => $s->master_id,
                ])
                ->filter(fn ($s) => $s['title'] !== '—');

            $slotInterval = $master->slot_interval ?? 30;
            $timezone = $master->getTimezone();
            $timezoneConfirmed = $master->isTimezoneConfirmed();
        }

        $masters = collect();
        if ($master->role->canManageTeam()) {
            $masters = $master->workspace
                ? $master->workspace->users()->where('is_master', true)->select('id', 'name')->get()
                : collect();
        } else {
            $masters = collect([['id' => $master->id, 'name' => $master->name]]);
        }

        return Inertia::render('admin/calendar', [
            'appointments' => $appointments,
            'initialBlockedTimes' => $blockedTimes,
            'workingHours' => $workingHours,
            'clients' => $clients,
            'services' => $services,
            'slotInterval' => $slotInterval,
            'timezone' => $timezone,
            'timezoneConfirmed' => $timezoneConfirmed,
            'prefillClientId' => $request->query('client_id'),
            'masters' => $masters,
            'dateRange' => [
                'start' => $rangeStart->format('Y-m-d'),
                'end' => $rangeEnd->format('Y-m-d'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $master = auth()->user();

        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'service_id' => 'required|exists:master_service,id',
            'date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'time' => 'required|date_format:H:i',
            'ignore_warnings' => 'sometimes|boolean',
            'confirm_outside_hours' => 'sometimes|boolean',
        ]);

        $client = Client::findOrFail($validated['client_id']);
        $this->authorize('view', $client);

        $masterService = MasterService::findOrFail($validated['service_id']);

        // Проверка принадлежности услуги мастерам текущего workspace (защита от IDOR)
        $workspaceMasterIds = $master->workspace
            ? $master->workspace->users()->pluck('id')->all()
            : [$master->id];

        if (! in_array($masterService->master_id, $workspaceMasterIds, true)) {
            abort(403, 'У вас нет прав на использование этой услуги.');
        }

        $targetMaster = User::find($masterService->master_id);

        $result = $this->bookingService->createManualAppointment(
            $targetMaster,
            $masterService,
            $validated['date'],
            $validated['time'],
            $validated['ignore_warnings'] ?? false,
            $validated['confirm_outside_hours'] ?? false,
            $client->id,
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
            'master_id' => 'sometimes|string|exists:users,id',
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

            $this->bookingService->updateStatus($appointment, $newStatus, auth()->user());
            unset($validated['status']);
        }

        if (isset($validated['start_time'])) {
            $tz = $appointment->master->getTimezone();
            $newDateTime = Carbon::parse($validated['start_time'], $tz);
            $newDate = $newDateTime->format('Y-m-d');
            $newTime = $newDateTime->format('H:i');

            $newMasterId = $validated['master_id'] ?? null;

            $result = $this->bookingService->rescheduleAppointment(
                $appointment,
                $newDate,
                $newTime,
                $validated['ignore_warnings'] ?? false,
                $validated['confirm_outside_hours'] ?? false,
                $newMasterId,
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
