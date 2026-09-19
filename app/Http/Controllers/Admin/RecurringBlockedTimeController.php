<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ExceptionType;
use App\Enums\RecurringSeriesStatus;
use App\Http\Controllers\Controller;
use App\Models\RecurringBlockedTimeException;
use App\Models\RecurringBlockedTimeSeries;
use App\Models\User;
use App\Services\Recurrence\RecurrenceRule;
use App\Services\Recurrence\RecurrenceService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RecurringBlockedTimeController extends Controller
{
    public function __construct(
        private readonly RecurrenceService $recurrenceService,
    ) {}

    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate($this->seriesValidationRules());
        $validated['timezone'] = $this->resolveTargetMaster($request, $validated)->getTimezone();

        $rule = RecurrenceRule::fromArray($validated);
        $rangeStart = Carbon::parse($validated['start_date'], $validated['timezone']);
        $rangeEnd = $rule->endsAt
            ? $rule->endsAt->copy()
            : Carbon::parse($validated['start_date'], $validated['timezone'])->addMonths(3);

        $occurrences = $this->recurrenceService->generateOccurrences($rule, $rangeStart, $rangeEnd);

        $targetMaster = $this->resolveTargetMaster($request, $validated);
        $results = [];
        foreach ($occurrences as $date) {
            $startTime = Carbon::parse($validated['start_time'], $validated['timezone']);
            $endTime = Carbon::parse($validated['end_time'], $validated['timezone']);

            $occurrenceStart = $date->copy()->setTimeFromTimeString($validated['start_time']);
            $occurrenceEnd = $date->copy()->setTimeFromTimeString($validated['end_time']);

            $utcStart = $occurrenceStart->copy()->timezone('UTC');
            $utcEnd = $occurrenceEnd->copy()->timezone('UTC');

            $conflicts = $targetMaster->masterAppointments()
                ->where('start_time', '<', $utcEnd)
                ->whereRaw("start_time + (COALESCE(duration, 60) * INTERVAL '1 minute') > ?", [$utcStart])
                ->whereIn('status', ['confirmed', 'pending'])
                ->get()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'time' => $a->start_time->timezone($validated['timezone'])->format('H:i'),
                ]);

            $results[] = [
                'date' => $date->format('Y-m-d'),
                'conflicts' => $conflicts->toArray(),
                'has_conflicts' => $conflicts->isNotEmpty(),
            ];
        }

        return response()->json([
            'occurrences' => $results,
            'total' => count($results),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->seriesValidationRules());

        // Manual time validation: end_time must be after start_time
        if ($validated['end_time'] <= $validated['start_time']) {
            throw ValidationException::withMessages([
                'end_time' => 'Время окончания должно быть после времени начала.',
            ]);
        }

        // Validate weekdays for weekly
        if ($validated['recurrence_type'] === 'weekly' && empty($validated['weekdays'])) {
            throw ValidationException::withMessages([
                'weekdays' => 'Для еженедельного повторения необходимо указать дни недели.',
            ]);
        }

        $targetMaster = $this->resolveTargetMaster($request, $validated);
        $validated['timezone'] = $targetMaster->getTimezone();
        unset($validated['master_id']);

        RecurringBlockedTimeSeries::create([
            'workspace_id' => $targetMaster->workspace_id,
            'user_id' => $targetMaster->id,
            'title' => $validated['title'],
            'reason' => $validated['reason'] ?? null,
            'start_date' => $validated['start_date'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'recurrence_type' => $validated['recurrence_type'],
            'interval' => $validated['interval'],
            'weekdays' => $validated['weekdays'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
            'timezone' => $validated['timezone'],
            'status' => RecurringSeriesStatus::Active,
        ]);

        return back()->with('success', 'Серия блокировок создана');
    }

    public function update(Request $request, RecurringBlockedTimeSeries $series): RedirectResponse
    {
        $this->authorizeSeries($request, $series);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'reason' => 'nullable|string|max:255',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i|after:start_time',
            'recurrence_type' => 'sometimes|in:daily,weekly',
            'interval' => 'sometimes|integer|min:1',
            'weekdays' => 'nullable|array|min:1',
            'weekdays.*' => 'integer|min:1|max:7|distinct',
            'ends_at' => 'nullable|date|after_or_equal:start_date',
            'status' => 'sometimes|in:active,paused,cancelled',
        ]);

        if (isset($validated['start_time']) && ! isset($validated['end_time'])) {
            $validated['end_time'] = $series->end_time;
        }
        if (isset($validated['end_time']) && ! isset($validated['start_time'])) {
            $validated['start_time'] = $series->start_time;
        }

        $series->update($validated);

        return back()->with('success', 'Серия обновлена');
    }

    public function destroy(Request $request, RecurringBlockedTimeSeries $series): RedirectResponse
    {
        $this->authorizeSeries($request, $series);

        $series->update(['status' => RecurringSeriesStatus::Cancelled]);

        return back()->with('success', 'Серия отменена');
    }

    public function updateOccurrence(
        Request $request,
        RecurringBlockedTimeSeries $series,
        string $date,
    ): RedirectResponse {
        $this->authorizeSeries($request, $series);

        $validated = $request->validate([
            'scope' => 'required|in:this,this_and_future,all',
            'override_start_time' => 'nullable|date_format:H:i',
            'override_end_time' => 'nullable|date_format:H:i',
            'override_title' => 'nullable|string|max:255',
            'override_reason' => 'nullable|string|max:255',
            'title' => 'sometimes|string|max:255',
            'reason' => 'nullable|string|max:255',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i',
            'recurrence_type' => 'sometimes|in:daily,weekly',
            'interval' => 'sometimes|integer|min:1',
            'weekdays' => 'nullable|array|min:1',
            'weekdays.*' => 'integer|min:1|max:7|distinct',
            'ends_at' => 'nullable|date',
            'status' => 'sometimes|in:active,paused,cancelled',
        ]);

        $occurrenceDate = Carbon::parse($date)->startOfDay();

        $scope = $validated['scope'];
        unset($validated['scope']);

        if ($scope === 'this') {
            $existing = RecurringBlockedTimeException::where('series_id', $series->id)
                ->where('occurrence_date', $occurrenceDate)
                ->first();

            $hasOverride = isset($validated['override_start_time'])
                || isset($validated['override_end_time'])
                || isset($validated['override_title'])
                || isset($validated['override_reason']);

            if ($hasOverride) {
                $updateData = [
                    'series_id' => $series->id,
                    'occurrence_date' => $occurrenceDate,
                    'type' => ExceptionType::Override,
                    'override_start_time' => $validated['override_start_time'] ?? $series->start_time,
                    'override_end_time' => $validated['override_end_time'] ?? $series->end_time,
                    'override_title' => $validated['override_title'] ?? $series->title,
                    'override_reason' => $validated['override_reason'] ?? $series->reason,
                ];

                if ($existing) {
                    $existing->update($updateData);
                } else {
                    RecurringBlockedTimeException::create($updateData);
                }

                return back()->with('success', 'Исключение создано/обновлено');
            }

            // No override data = skip this occurrence
            if ($existing) {
                $existing->update(['type' => ExceptionType::Skip]);
            } else {
                RecurringBlockedTimeException::create([
                    'series_id' => $series->id,
                    'occurrence_date' => $occurrenceDate,
                    'type' => ExceptionType::Skip,
                ]);
            }

            return back()->with('success', 'Дата пропущена в серии');
        }

        if ($scope === 'this_and_future') {
            // End old series the day before this occurrence
            $series->update([
                'ends_at' => $occurrenceDate->copy()->subDay(),
            ]);

            // Create new series starting from this occurrence
            $newSeries = RecurringBlockedTimeSeries::create([
                'workspace_id' => $series->workspace_id,
                'user_id' => $series->user_id,
                'title' => $validated['title'] ?? $series->title,
                'reason' => $validated['reason'] ?? $series->reason,
                'start_date' => $occurrenceDate->format('Y-m-d'),
                'start_time' => $validated['start_time'] ?? $series->start_time,
                'end_time' => $validated['end_time'] ?? $series->end_time,
                'recurrence_type' => $validated['recurrence_type'] ?? $series->recurrence_type,
                'interval' => $validated['interval'] ?? $series->interval,
                'weekdays' => $validated['weekdays'] ?? $series->weekdays,
                'ends_at' => $validated['ends_at'] ?? $series->ends_at,
                'timezone' => $series->timezone,
                'status' => RecurringSeriesStatus::Active,
            ]);

            return back()->with('success', 'Серия разделена');
        }

        // scope === 'all'
        $series->update($validated);

        return back()->with('success', 'Серия обновлена');
    }

    public function destroyOccurrence(
        Request $request,
        RecurringBlockedTimeSeries $series,
        string $date,
    ): RedirectResponse {
        $this->authorizeSeries($request, $series);

        $validated = $request->validate([
            'scope' => 'required|in:this,this_and_future,all',
        ]);

        $occurrenceDate = Carbon::parse($date)->startOfDay();
        $scope = $validated['scope'];

        if ($scope === 'this') {
            RecurringBlockedTimeException::updateOrCreate(
                ['series_id' => $series->id, 'occurrence_date' => $occurrenceDate],
                ['type' => ExceptionType::Skip],
            );

            return back()->with('success', 'Дата пропущена');
        }

        if ($scope === 'this_and_future') {
            $series->update([
                'ends_at' => $occurrenceDate->copy()->subDay(),
            ]);

            return back()->with('success', 'Серия завершена');
        }

        // scope === 'all'
        $series->update(['status' => RecurringSeriesStatus::Cancelled]);

        return back()->with('success', 'Серия отменена');
    }

    private function seriesValidationRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'reason' => 'nullable|string|max:255',
            'start_date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'recurrence_type' => 'required|in:daily,weekly',
            'interval' => 'required|integer|min:1',
            'weekdays' => 'nullable|array|min:1',
            'weekdays.*' => 'integer|min:1|max:7|distinct',
            'ends_at' => 'nullable|date|after_or_equal:start_date',
            'master_id' => 'nullable|uuid|exists:users,id',
        ];
    }

    private function resolveTargetMaster(Request $request, array $validated): User
    {
        $user = $request->user();
        $isAdminOrOwner = $user->role->canManageTeam();

        if ($isAdminOrOwner && ! empty($validated['master_id'])) {
            return User::where('id', $validated['master_id'])
                ->where('workspace_id', $user->workspace_id)
                ->where('is_master', true)
                ->firstOrFail();
        }

        return $user;
    }

    private function authorizeSeries(Request $request, RecurringBlockedTimeSeries $series): void
    {
        $user = $request->user();

        if ($user->role->canManageTeam()) {
            abort_unless(
                $series->workspace_id === $user->workspace_id,
                403,
                'Серия принадлежит другому workspace.'
            );
        } else {
            abort_unless($series->user_id === $user->id, 403);
        }
    }
}
