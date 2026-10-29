<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AppointmentNotificationEventType;
use App\Enums\AppointmentStatus;
use App\Enums\RecurringSeriesStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Exceptions\UpdateStatusRejectedException;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\BlockedTime;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\RecurringBlockedTimeSeries;
use App\Models\User;
use App\Models\WorkingHour;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingService;
use App\Services\Notification\AppointmentNotificationRecorder;
use App\Services\Notification\AppointmentNotificationSummary;
use App\Services\Recurrence\RecurrenceRule;
use App\Services\Recurrence\RecurrenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

        $tz = $master->getTimezone();

        $rangeStart = isset($validated['start'])
            ? Carbon::parse($validated['start'], $tz)->startOfDay()->utc()
            : Carbon::now($tz)->subWeeks(3)->startOfDay()->utc();
        $rangeEnd = isset($validated['end'])
            ? Carbon::parse($validated['end'], $tz)->endOfDay()->utc()
            : Carbon::now($tz)->addWeeks(3)->endOfDay()->utc();

        $isAdminOrOwner = $master->role->canManageTeam();

        if ($isAdminOrOwner) {
            $masterIds = $master->workspace
                ? $master->workspace->users()->where('is_master', true)->pluck('id')
                : collect([$master->id]);

            $appointments = Appointment::whereIn('master_id', $masterIds)
                ->with(['client', 'master', 'masterService.catalog', 'recurringSeries'])
                ->whereBetween('start_time', [
                    $rangeStart,
                    $rangeEnd,
                ])
                ->where(function ($q) {
                    $q->whereNotNull('client_id')
                        ->orWhereNotNull('source');
                })
                ->get();

            $notificationSummaries = app(AppointmentNotificationSummary::class)
                ->forAppointments($appointments);

            $appointments = $appointments
                ->map(function (Appointment $a) use ($notificationSummaries) {
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
                        'reminder_24h_failed_at' => $a->reminder_24h_failed_at?->toIso8601String(),
                        'reminder_final_sent_at' => $a->reminder_final_sent_at?->toIso8601String(),
                        'reminder_final_failed_at' => $a->reminder_final_failed_at?->toIso8601String(),
                        'client_notifications' => $notificationSummaries[$a->id] ?? null,
                        'recurring_series_id' => $a->recurring_series_id,
                        'recurring_occurrence_date' => $a->recurring_occurrence_date,
                        'recurring_series' => $a->recurringSeries ? [
                            'id' => $a->recurringSeries->id,
                            'start_time' => $a->recurringSeries->start_time,
                            'recurrence_type' => $a->recurringSeries->recurrence_type->value,
                            'interval' => $a->recurringSeries->interval,
                            'weekdays' => $a->recurringSeries->weekdays,
                            'ends_at' => $a->recurringSeries->ends_at?->format('Y-m-d'),
                            'occurrences_count' => $a->recurringSeries->occurrences_count,
                            'master_service_id' => $a->recurringSeries->master_service_id,
                            'status' => $a->recurringSeries->status->value,
                        ] : null,
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

            // Recurring blocked time occurrences
            $recurringService = new RecurrenceService;
            $rangeStartDate = Carbon::parse($validated['start'] ?? Carbon::now($tz)->subWeeks(3)->format('Y-m-d'), $tz)->startOfDay();
            $rangeEndDate = Carbon::parse($validated['end'] ?? Carbon::now($tz)->addWeeks(3)->format('Y-m-d'), $tz)->endOfDay();

            $recurringSeries = RecurringBlockedTimeSeries::whereIn('user_id', $masterIds)
                ->where('status', RecurringSeriesStatus::Active)
                ->with('exceptions')
                ->get();

            foreach ($recurringSeries as $series) {
                $rule = RecurrenceRule::fromArray([
                    'recurrence_type' => $series->recurrence_type->value,
                    'interval' => $series->interval,
                    'weekdays' => $series->weekdays,
                    'start_date' => $series->start_date->format('Y-m-d'),
                    'ends_at' => $series->ends_at?->format('Y-m-d'),
                    'timezone' => $series->timezone,
                ]);

                $occurrences = $recurringService->generateOccurrences($rule, $rangeStartDate, $rangeEndDate);

                foreach ($occurrences as $date) {
                    $dateKey = $date->format('Y-m-d');
                    $exception = $series->exceptions->first(
                        fn ($ex) => $ex->occurrence_date->format('Y-m-d') === $dateKey,
                    );

                    if ($exception && $exception->type->value === 'skip') {
                        continue;
                    }

                    $startTime = ($exception && $exception->type->value === 'override')
                        ? ($exception->override_start_time ?? $series->start_time)
                        : $series->start_time;
                    $endTime = ($exception && $exception->type->value === 'override')
                        ? ($exception->override_end_time ?? $series->end_time)
                        : $series->end_time;

                    $blockedTimes->push([
                        'id' => "recurring:{$series->id}:{$dateKey}",
                        'date' => $dateKey,
                        'end_date' => $dateKey,
                        'start_time' => substr($startTime, 0, 5),
                        'end_time' => substr($endTime, 0, 5),
                        'reason' => $series->reason ?? $series->title,
                        'user_id' => $series->user_id,
                        'is_recurring' => true,
                        'series_id' => $series->id,
                    ]);
                }
            }

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
                ->whereHas('catalog', fn ($q) => $q->where('is_active', true))
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
                ->where(function ($q) {
                    $q->whereNotNull('client_id')
                        ->orWhereNotNull('source');
                })
                ->get();

            $notificationSummaries = app(AppointmentNotificationSummary::class)
                ->forAppointments($appointments);

            $appointments = $appointments
                ->map(function (Appointment $a) use ($tz, $notificationSummaries) {
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
                        'reminder_24h_failed_at' => $a->reminder_24h_failed_at?->toIso8601String(),
                        'reminder_final_sent_at' => $a->reminder_final_sent_at?->toIso8601String(),
                        'reminder_final_failed_at' => $a->reminder_final_failed_at?->toIso8601String(),
                        'client_notifications' => $notificationSummaries[$a->id] ?? null,
                        'recurring_series_id' => $a->recurring_series_id,
                        'recurring_occurrence_date' => $a->recurring_occurrence_date,
                        'recurring_series' => $a->recurringSeries ? [
                            'id' => $a->recurringSeries->id,
                            'start_time' => $a->recurringSeries->start_time,
                            'recurrence_type' => $a->recurringSeries->recurrence_type->value,
                            'interval' => $a->recurringSeries->interval,
                            'weekdays' => $a->recurringSeries->weekdays,
                            'ends_at' => $a->recurringSeries->ends_at?->format('Y-m-d'),
                            'occurrences_count' => $a->recurringSeries->occurrences_count,
                            'master_service_id' => $a->recurringSeries->master_service_id,
                            'status' => $a->recurringSeries->status->value,
                        ] : null,
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
                ->whereHas('catalog', fn ($q) => $q->where('is_active', true))
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

        if (! $masterService->catalog || ! $masterService->catalog->is_active) {
            abort(422, 'Эта услуга больше недоступна для записи.');
        }

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

    /**
     * @return Response|RedirectResponse|JsonResponse
     */
    public function updateStatus(Request $request, Appointment $appointment)
    {
        $this->authorize('update', $appointment);

        $validated = $request->validate([
            'status' => 'sometimes|in:booked,pending_payment,prepaid,no_show,paid,cancelled',
            'start_time' => 'sometimes|date',
            'master_id' => 'sometimes|string|exists:users,id',
            'ignore_warnings' => 'sometimes|boolean',
            'confirm_outside_hours' => 'sometimes|boolean',
            'service_id' => 'sometimes|nullable|exists:master_service,id',
            'duration' => 'sometimes|nullable|integer|min:1',
            'price' => 'sometimes|nullable|numeric|min:0',
        ]);

        $hasFieldEdit = array_key_exists('service_id', $validated)
            || array_key_exists('duration', $validated)
            || array_key_exists('price', $validated);

        if (! isset($validated['status']) && ! isset($validated['start_time']) && ! $hasFieldEdit) {
            return back()->withErrors([
                'status' => 'Необходимо указать статус, новое время или изменить услугу/длительность/цену.',
            ]);
        }

        try {
            // Одно пользовательское сохранение = одна транзакция:
            // статус, перенос и поля читаются под блокировкой, применяются
            // атомарно и порождают максимум одно согласованное сообщение.
            // Ошибка любой части откатывает всё, ничего не остаётся
            // применённым частично.
            return DB::transaction(function () use ($appointment, $validated) {
                $locked = Appointment::whereKey($appointment->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $locked->load(['master', 'client', 'masterService.catalog']);

                $newStatus = isset($validated['status'])
                    ? AppointmentStatus::from($validated['status'])
                    : null;
                $willReschedule = isset($validated['start_time']);

                // ─── Валидации до каких-либо мутаций ───

                if ($newStatus !== null && ! $locked->status->canTransitionTo($newStatus)) {
                    throw new UpdateStatusRejectedException(
                        'status',
                        "Невозможно перевести запись из «{$locked->status->label()}» в «{$newStatus->label()}».",
                        errorsOnly: true,
                    );
                }

                // Итоговый статус (текущий или запрошенный) определяет
                // допустимость остальных частей сохранения — ошибка второй
                // части не оставляет первую уже применённой.
                $effectiveStatus = $newStatus ?? $locked->status;
                if ($willReschedule && in_array($effectiveStatus, [
                    AppointmentStatus::Cancelled,
                    AppointmentStatus::Paid,
                    AppointmentStatus::NoShow,
                ], true)) {
                    throw new UpdateStatusRejectedException(
                        'invalid_status',
                        'Невозможно перенести запись со статусом «'.$effectiveStatus->label().'».',
                        asJson: true,
                    );
                }

                // Доступность итогового интервала при изменении
                // услуги/длительности — существующим механизмом
                // доступности, без обхода действующих ограничений.
                if (! $willReschedule
                    && (array_key_exists('service_id', $validated) || array_key_exists('duration', $validated))) {
                    $plannedDuration = $this->plannedDuration($locked, $validated);
                    if ($plannedDuration !== null
                        && app(AvailabilityService::class)
                            ->isSlotBookedOrBlocked(
                                $locked->master,
                                Carbon::parse($locked->start_time),
                                $plannedDuration,
                                $locked->id,
                            )) {
                        throw new UpdateStatusRejectedException(
                            'time',
                            'Этот интервал уже занят другим событием.',
                            asJson: true,
                        );
                    }
                }

                // ─── Мутации под блокировкой ───

                // Снимок исходных время/мастер ДО каких-либо мутаций:
                // после сохранений getOriginal ненадёжен, а $locked не
                // обновляется сервисом (тот загружает свой экземпляр).
                $oldStartTime = $locked->start_time->toIso8601String();
                $oldMasterId = $locked->master_id;

                // Фактическое изменение услуги/длительности/цены одной
                // записью; доступ к целевой услуге — по действующим правилам
                // приложения (как при создании записи).
                $fieldChanges = $this->applyFieldChanges($locked, $validated);

                if ($newStatus !== null) {
                    $this->bookingService->updateStatus($locked, $newStatus, auth()->user());
                } elseif ($locked->isDirty()) {
                    $locked->save();
                }

                // Актуальная запись после переноса: сервис загружает и
                // сохраняет ДРУГОЙ экземпляр через lockForUpdate, поэтому
                // событие собирается из результата сервиса, а не из
                // прежнего $locked с устаревшим start_time/master_id.
                $notified = $locked;

                if ($willReschedule) {
                    if ($locked->isDirty()) {
                        // Поля сохраняются до переноса, чтобы проверка
                        // доступности видела итоговую длительность интервала.
                        $locked->save();
                    }

                    $tz = $locked->master->getTimezone();
                    $newDateTime = Carbon::parse($validated['start_time'], $tz);

                    $result = $this->bookingService->rescheduleAppointment(
                        $locked,
                        $newDateTime->format('Y-m-d'),
                        $newDateTime->format('H:i'),
                        $validated['ignore_warnings'] ?? false,
                        $validated['confirm_outside_hours'] ?? false,
                        $validated['master_id'] ?? null,
                        notifyClient: false,
                    );

                    if (! ($result['success'] ?? false)) {
                        // Исключение откатывает и сохранение полей, и статус.
                        $errorMap = [
                            'break_intersection' => 'lunch_intersection',
                            'outside_working_hours' => 'outside_working_hours',
                        ];
                        throw new UpdateStatusRejectedException(
                            $errorMap[$result['error']] ?? 'time',
                            (string) $result['message'],
                            extra: [
                                'error' => $result['error'],
                                'break_info' => $result['break_info'] ?? null,
                            ],
                        );
                    }

                    // Актуальная запись с новым временем/мастером и
                    // свежими отношениями — источник данных события.
                    $notified = Appointment::with(['master', 'client', 'masterService.catalog'])
                        ->findOrFail($locked->getKey());
                }

                // ─── Одно согласованное сообщение на одно сохранение ───

                // Отмена уже записана transition'ом (_CancelledByMaster_);
                // поля и перенос в том же запросе применяются молча.
                $cancelledViaStatus = $newStatus === AppointmentStatus::Cancelled;

                if (! $cancelledViaStatus && $notified->client_id !== null) {
                    $recorder = app(AppointmentNotificationRecorder::class);

                    if ($willReschedule && $notified->start_time->isFuture()) {
                        $version = $recorder->bumpVersion($notified);
                        $recorder->record(
                            $notified,
                            AppointmentNotificationEventType::Rescheduled,
                            [
                                'old_start_time' => $oldStartTime,
                                'new_start_time' => $notified->start_time->toIso8601String(),
                                'old_master_id' => $oldMasterId,
                                'new_master_id' => $notified->master_id,
                                'service_name' => $notified->display_name,
                                'changes' => $fieldChanges,
                            ],
                            $version,
                        );
                    } elseif ($fieldChanges !== []) {
                        $version = $recorder->bumpVersion($locked);
                        $recorder->record(
                            $locked,
                            AppointmentNotificationEventType::FieldsChanged,
                            ['changes' => $fieldChanges],
                            $version,
                        );
                    }
                }

                return back();
            });
        } catch (UpdateStatusRejectedException $e) {
            if ($e->errorsOnly) {
                return back()->withErrors([$e->errorKey => $e->detail]);
            }

            if ($request->header('X-Inertia') && ! $e->asJson) {
                return back()->withErrors([$e->errorKey => $e->detail])->withInput();
            }

            return response()->json(
                ['error' => $e->errorKey, 'message' => $e->detail] + $e->extra,
                $e->httpStatus,
            );
        } catch (InvalidStatusTransitionException) {
            return back()->withErrors([
                'status' => 'Статус записи уже изменился. Обновите страницу и попробуйте снова.',
            ]);
        }
    }

    /**
     * Итоговая длительность интервала с учётом правок в запросе
     * (явный duration имеет приоритет над снимком услуги).
     *
     * @param  array<string, mixed>  $validated
     */
    private function plannedDuration(Appointment $appointment, array $validated): ?int
    {
        if (array_key_exists('duration', $validated) && $validated['duration'] !== null) {
            return (int) $validated['duration'];
        }

        if (array_key_exists('service_id', $validated) && $validated['service_id'] !== null) {
            $service = MasterService::query()
                ->whereKey($validated['service_id'])
                ->first();

            return $service?->effective_duration;
        }

        return null;
    }

    /**
     * Применяет изменения услуги/длительности/цены к заблокированной
     * строке (без сохранения) и возвращает сложенные изменения для
     * единого сообщения. Чужая/неактивная услуга отклоняется без
     * изменения записи и события.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, array{old: int|float|string, new: int|float|string}>
     */
    private function applyFieldChanges(Appointment $appointment, array $validated): array
    {
        $changes = [];

        $originalServiceName = $appointment->display_name;
        $originalDuration = (int) ($appointment->duration ?? 0);
        $originalPrice = (float) ($appointment->price ?? 0);

        if (array_key_exists('service_id', $validated) && $validated['service_id'] !== null) {
            $newService = MasterService::query()
                ->whereKey($validated['service_id'])
                ->firstOrFail();
            $newService->load('catalog');

            if (! $newService->catalog || ! $newService->catalog->is_active) {
                abort(422, 'Эта услуга больше недоступна для записи.');
            }

            $authUser = User::findOrFail(auth()->id());
            $workspaceMasterIds = $authUser->workspace
                ? $authUser->workspace->users()->pluck('id')->all()
                : [$authUser->id];

            if (! in_array($newService->master_id, $workspaceMasterIds, true)) {
                abort(403, 'У вас нет прав на использование этой услуги.');
            }

            if ($newService->id !== $appointment->master_service_id) {
                $newServiceTitle = $newService->catalog->title ?? $originalServiceName;
                $changes['service_name'] = [
                    'old' => $originalServiceName,
                    'new' => $newServiceTitle,
                ];
                $appointment->master_service_id = $newService->id;
                $appointment->service_name = $newServiceTitle;
                // Явные override'ы ниже имеют приоритет; без них — снимок услуги.
                if (! array_key_exists('duration', $validated)) {
                    $newDurationFromService = $newService->effective_duration;
                    if ($originalDuration !== $newDurationFromService) {
                        $appointment->duration = $newDurationFromService;
                        $changes['duration'] = [
                            'old' => $originalDuration,
                            'new' => $newDurationFromService,
                        ];
                    }
                }
                if (! array_key_exists('price', $validated)) {
                    $newPriceFromService = (float) $newService->effective_price;
                    if (abs($originalPrice - $newPriceFromService) > 0.001) {
                        $appointment->price = $newPriceFromService;
                        $changes['price'] = [
                            'old' => $originalPrice,
                            'new' => $newPriceFromService,
                        ];
                    }
                }
            }
        }

        if (array_key_exists('duration', $validated) && $validated['duration'] !== null) {
            $newDuration = (int) $validated['duration'];
            if ($originalDuration !== $newDuration) {
                $changes['duration'] = ['old' => $originalDuration, 'new' => $newDuration];
                $appointment->duration = $newDuration;
            }
        }

        if (array_key_exists('price', $validated) && $validated['price'] !== null) {
            $newPrice = (float) $validated['price'];
            if (abs($originalPrice - $newPrice) > 0.001) {
                $changes['price'] = ['old' => $originalPrice, 'new' => $newPrice];
                $appointment->price = $newPrice;
            }
        }

        return $changes;
    }
}
