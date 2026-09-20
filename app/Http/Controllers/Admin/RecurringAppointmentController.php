<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RecurrenceType;
use App\Enums\RecurringSeriesStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\User;
use App\Services\Booking\RecurringAppointmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecurringAppointmentController extends Controller
{
    public function __construct(
        private RecurringAppointmentService $recurringService,
    ) {}

    public function preview(Request $request): JsonResponse
    {
        $master = auth()->user();
        $validated = $request->validate([
            'master_id' => 'nullable|exists:users,id',
            'service_id' => 'required|exists:master_service,id',
            'date' => 'required|date_format:Y-m-d',
            'time' => 'required|date_format:H:i',
            'recurrence_type' => 'required|in:daily,weekly',
            'interval' => 'required|integer|min:1',
            'weekdays' => 'nullable|array',
            'weekdays.*' => 'integer|min:1|max:7',
            'ends_at' => 'nullable|date_format:Y-m-d',
            'occurrences_count' => 'nullable|integer|min:2|max:100',
            'exclude_appointment_id' => 'nullable|exists:appointments,id',
        ]);

        if (empty($validated['ends_at']) && empty($validated['occurrences_count'])) {
            return response()->json([
                'message' => 'Укажите дату окончания или количество повторений.',
            ], 422);
        }

        $targetMaster = $this->resolveMaster($master, $validated['master_id'] ?? null);
        $service = MasterService::findOrFail($validated['service_id']);
        $this->authorizeService($master, $service);

        $startDate = \Illuminate\Support\Carbon::parse($validated['date'], $targetMaster->getTimezone());

        $result = $this->recurringService->preview(
            master: $targetMaster,
            startDate: $startDate,
            startTime: $validated['time'],
            durationMinutes: $service->effective_duration,
            recurrenceType: RecurrenceType::from($validated['recurrence_type']),
            interval: $validated['interval'],
            weekdays: $validated['weekdays'] ?? null,
            endsAt: !empty($validated['ends_at']) ? \Illuminate\Support\Carbon::parse($validated['ends_at'], $targetMaster->getTimezone()) : null,
            occurrencesCount: $validated['occurrences_count'] ?? null,
            excludeAppointmentId: $validated['exclude_appointment_id'] ?? null,
        );

        return response()->json($result);
    }

    public function store(Request $request): JsonResponse
    {
        $master = auth()->user();
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'service_id' => 'required|exists:master_service,id',
            'date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'time' => 'required|date_format:H:i',
            'recurrence_type' => 'required|in:daily,weekly',
            'interval' => 'required|integer|min:1',
            'weekdays' => 'nullable|array',
            'weekdays.*' => 'integer|min:1|max:7',
            'ends_at' => 'nullable|date_format:Y-m-d',
            'occurrences_count' => 'nullable|integer|min:2|max:100',
            'allowed_dates' => 'required|array|min:1',
            'allowed_dates.*' => 'date_format:Y-m-d',
        ]);

        if (empty($validated['ends_at']) && empty($validated['occurrences_count'])) {
            return response()->json([
                'message' => 'Укажите дату окончания или количество повторений.',
            ], 422);
        }

        $targetMaster = $this->resolveMaster($master, null);
        $service = MasterService::findOrFail($validated['service_id']);
        $this->authorizeService($master, $service);

        $client = Client::findOrFail($validated['client_id']);
        $this->authorize('view', $client);

        $series = $this->recurringService->createSeries(
            master: $targetMaster,
            clientId: $client->id,
            masterServiceId: $service->id,
            startDate: $validated['date'],
            startTime: $validated['time'],
            recurrenceType: RecurrenceType::from($validated['recurrence_type']),
            interval: $validated['interval'],
            weekdays: $validated['weekdays'] ?? null,
            endsAt: $validated['ends_at'] ?? null,
            occurrencesCount: $validated['occurrences_count'] ?? null,
            allowedDates: $validated['allowed_dates'],
        );

        return response()->json([
            'series_id' => $series->id,
            'created' => $series->appointments()->count(),
        ], 201);
    }

    public function fromExisting(Request $request, Appointment $appointment): JsonResponse
    {
        $master = auth()->user();
        $this->authorize('view', $appointment);

        $validated = $request->validate([
            'recurrence_type' => 'required|in:daily,weekly',
            'interval' => 'required|integer|min:1',
            'weekdays' => 'nullable|array',
            'weekdays.*' => 'integer|min:1|max:7',
            'ends_at' => 'nullable|date_format:Y-m-d',
            'occurrences_count' => 'nullable|integer|min:2|max:100',
            'allowed_dates' => 'required|array|min:1',
            'allowed_dates.*' => 'date_format:Y-m-d',
        ]);

        if (empty($validated['ends_at']) && empty($validated['occurrences_count'])) {
            return response()->json([
                'message' => 'Укажите дату окончания или количество повторений.',
            ], 422);
        }

        if ($appointment->recurring_series_id) {
            return response()->json([
                'message' => 'Эта запись уже является частью серии.',
            ], 422);
        }

        $targetMaster = $appointment->master;
        $tz = $targetMaster->getTimezone();
        $startDate = $appointment->start_time->timezone($tz)->format('Y-m-d');
        $startTime = $appointment->start_time->timezone($tz)->format('H:i');

        $series = $this->recurringService->createSeries(
            master: $targetMaster,
            clientId: $appointment->client_id,
            masterServiceId: $appointment->master_service_id,
            startDate: $startDate,
            startTime: $startTime,
            recurrenceType: RecurrenceType::from($validated['recurrence_type']),
            interval: $validated['interval'],
            weekdays: $validated['weekdays'] ?? null,
            endsAt: $validated['ends_at'] ?? null,
            occurrencesCount: $validated['occurrences_count'] ?? null,
            allowedDates: $validated['allowed_dates'],
            existingAppointment: $appointment,
        );

        return response()->json([
            'series_id' => $series->id,
            'created' => $series->appointments()->count(),
        ], 201);
    }

    /**
     * Edit only this occurrence: reschedule date/time.
     */
    public function editOnlyThis(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorize('update', $appointment);

        if (! $appointment->recurring_series_id) {
            return response()->json(['message' => 'Запись не является частью серии.'], 422);
        }

        $validated = $request->validate([
            'start_time' => 'required|date',
        ]);

        $tz = $appointment->master->getTimezone();
        $newDateTime = \Illuminate\Support\Carbon::parse($validated['start_time'], $tz);
        $newDate = $newDateTime->format('Y-m-d');
        $newTime = $newDateTime->format('H:i');

        $result = $this->recurringService->editOnlyThis($appointment, $newDate, $newTime);

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'message' => $result['message'] ?? 'Ошибка переноса.',
            ], 422);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Cancel only this occurrence.
     */
    public function cancelOnlyThis(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorize('update', $appointment);

        if (! $appointment->recurring_series_id) {
            return response()->json(['message' => 'Запись не является частью серии.'], 422);
        }

        $this->recurringService->cancelOnlyThis($appointment);

        return response()->json(['success' => true]);
    }

    /**
     * Preview for split operations (edit/cancel this-and-future).
     */
    public function previewSplit(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorize('view', $appointment);

        $validated = $request->validate([
            'recurrence_type' => 'required|in:daily,weekly',
            'interval' => 'required|integer|min:1',
            'weekdays' => 'nullable|array',
            'weekdays.*' => 'integer|min:1|max:7',
            'ends_at' => 'nullable|date_format:Y-m-d',
            'occurrences_count' => 'nullable|integer|min:2|max:100',
            'start_time' => 'nullable|date_format:H:i',
        ]);

        if (empty($validated['ends_at']) && empty($validated['occurrences_count'])) {
            return response()->json([
                'message' => 'Укажите дату окончания или количество повторений.',
            ], 422);
        }

        $result = $this->recurringService->previewSplit(
            splitAppointment: $appointment,
            recurrenceType: RecurrenceType::from($validated['recurrence_type']),
            interval: $validated['interval'],
            weekdays: $validated['weekdays'] ?? null,
            endsAt: ! empty($validated['ends_at']) ? \Illuminate\Support\Carbon::parse($validated['ends_at']) : null,
            occurrencesCount: $validated['occurrences_count'] ?? null,
            startTime: $validated['start_time'] ?? null,
        );

        return response()->json($result);
    }

    /**
     * Edit this and future occurrences: split series + create new.
     */
    public function editThisAndFuture(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorize('update', $appointment);

        if (! $appointment->recurring_series_id) {
            return response()->json(['message' => 'Запись не является частью серии.'], 422);
        }

        $validated = $request->validate([
            'service_id' => 'required|uuid|exists:master_service,id',
            'start_time' => 'required|date_format:H:i',
            'recurrence_type' => 'required|in:daily,weekly',
            'interval' => 'required|integer|min:1',
            'weekdays' => 'nullable|array',
            'weekdays.*' => 'integer|min:1|max:7',
            'ends_at' => 'nullable|date_format:Y-m-d',
            'occurrences_count' => 'nullable|integer|min:2|max:100',
            'allowed_dates' => 'required|array|min:1',
            'allowed_dates.*' => 'date_format:Y-m-d',
        ]);

        if (empty($validated['ends_at']) && empty($validated['occurrences_count'])) {
            return response()->json([
                'message' => 'Укажите дату окончания или количество повторений.',
            ], 422);
        }

        $series = $appointment->recurringSeries;
        if (! $series || $series->status !== RecurringSeriesStatus::Active) {
            return response()->json(['message' => 'Серия неактивна.'], 422);
        }

        $masterService = MasterService::findOrFail($validated['service_id']);
        $this->authorizeService(auth()->user(), $masterService);

        $newSeries = $this->recurringService->splitSeries(
            series: $series,
            splitAppointment: $appointment,
            newParams: [
                'recurrence_type' => $validated['recurrence_type'],
                'interval' => $validated['interval'],
                'weekdays' => $validated['weekdays'],
                'ends_at' => $validated['ends_at'] ?? null,
                'occurrences_count' => $validated['occurrences_count'] ?? null,
                'master_service_id' => $masterService->id,
                'start_time' => $validated['start_time'],
            ],
            previewResult: [
                'dates' => $validated['allowed_dates'],
            ],
        );

        return response()->json([
            'series_id' => $newSeries->id,
            'created' => $newSeries->appointments()->count(),
        ], 201);
    }

    /**
     * Cancel this and all future occurrences.
     */
    public function cancelThisAndFuture(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorize('update', $appointment);

        if (! $appointment->recurring_series_id) {
            return response()->json(['message' => 'Запись не является частью серии.'], 422);
        }

        $series = $appointment->recurringSeries;
        if (! $series) {
            return response()->json(['message' => 'Серия не найдена.'], 422);
        }

        $cancelledCount = $this->recurringService->cancelThisAndFuture($series, $appointment);

        return response()->json([
            'cancelled' => $cancelledCount,
        ]);
    }

    /**
     * Cancel ALL active appointments in the series.
     */
    public function cancelWholeSeries(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorize('update', $appointment);

        if (! $appointment->recurring_series_id) {
            return response()->json(['message' => 'Запись не является частью серии.'], 422);
        }

        $series = $appointment->recurringSeries;
        if (! $series) {
            return response()->json(['message' => 'Серия не найдена.'], 422);
        }

        $cancelledCount = $this->recurringService->cancelWholeSeries($series);

        return response()->json([
            'cancelled' => $cancelledCount,
        ]);
    }

    private function resolveMaster(User $authUser, ?string $masterId): User
    {
        if ($masterId) {
            $master = User::findOrFail($masterId);
            $workspaceMasterIds = $authUser->workspace
                ? $authUser->workspace->users()->pluck('id')->all()
                : [$authUser->id];

            if (! in_array($master->id, $workspaceMasterIds, true)) {
                abort(403, 'Мастер из другого воркспейса.');
            }

            return $master;
        }

        return $authUser;
    }

    private function authorizeService(User $authUser, MasterService $service): void
    {
        if (! $service->catalog || ! $service->catalog->is_active) {
            abort(422, 'Эта услуга больше недоступна для записи.');
        }

        $workspaceMasterIds = $authUser->workspace
            ? $authUser->workspace->users()->pluck('id')->all()
            : [$authUser->id];

        if (! in_array($service->master_id, $workspaceMasterIds, true)) {
            abort(403, 'У вас нет прав на использование этой услуги.');
        }
    }
}
