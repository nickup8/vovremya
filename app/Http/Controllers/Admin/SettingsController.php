<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlockedTime;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class SettingsController extends Controller
{
    public function index(): InertiaResponse
    {
        $user = auth()->user();

        return Inertia::render('admin/settings', [
            'profile' => [
                'name' => $user->name,
                'phone' => $user->phone,
                'master_slug' => $user->master_slug,
                'specialty' => $user->specialty,
                'address' => $user->address,
                'avatar_url' => $user->avatar_url,
                'telegram_id' => $user->telegram_id,
                'max_id' => $user->max_id,
                'soft_deposit' => $user->soft_deposit,
                'deposit_timeout' => $user->deposit_timeout,
                'deposit_percent' => $user->deposit_percent,
                'slot_interval' => $user->slot_interval,
                'telegram_notifications' => $user->telegram_notifications,
                'max_notifications' => $user->max_notifications,
                'timezone' => $user->getTimezone(),
                'timezone_confirmed' => $user->isTimezoneConfirmed(),
                'booking_flow_type' => $user->getBookingFlowType(),
                'custom_prepayment_message' => $user->getCustomPrepaymentMessage(),
                'reminder_hours_before_final' => $user->getReminderHoursBeforeFinal(),
            ],
            'services' => $user->services()->get(),
            'workingHours' => $user->workingHours()->get(),
            'blockedTimes' => $user->blockedTimes()->get(),
        ]);
    }

    public function update(Request $request)
    {
        $user = auth()->user();

        $allRules = [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'specialty' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:500',
            'master_slug' => [
                'nullable',
                'string',
                'max:100',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('users', 'master_slug')->ignore($user->id),
            ],
            'telegram_id' => 'nullable|string|max:100',
            'max_id' => 'nullable|string|max:100',
            'soft_deposit' => 'boolean',
            'deposit_timeout' => 'nullable|integer|min:1',
            'deposit_percent' => 'nullable|integer|min:1|max:100',
            'telegram_notifications' => 'boolean',
            'max_notifications' => 'boolean',
            'booking_flow_type' => ['nullable', Rule::in(['free_verification', 'prepayment_custom'])],
            'custom_prepayment_message' => ['nullable', 'string', 'max:1000'],
            'reminder_hours_before_final' => ['nullable', 'integer', Rule::in([2, 3])],
        ];

        $sentFields = $request->only(array_keys($allRules));
        $activeRules = array_intersect_key($allRules, $sentFields);

        if (empty($activeRules)) {
            return back()->with('error', 'Нет данных для обновления');
        }

        $validated = $request->validate($activeRules);

        $jsonFields = ['booking_flow_type', 'custom_prepayment_message', 'reminder_hours_before_final'];
        $settingsData = array_intersect_key($validated, array_flip($jsonFields));
        $columnData = array_diff_key($validated, array_flip($jsonFields));

        $user->update($columnData);

        $currentSettings = $user->settings ?? [];
        $user->update(['settings' => array_merge($currentSettings, $settingsData)]);

        return back()->with('success', 'Настройки успешно сохранены');
    }

    public function updateAvatar(Request $request)
    {
        \Log::info('Avatar upload request received', [
            'files' => $request->allFiles(),
            'has_file' => $request->hasFile('avatar'),
        ]);

        if (! $request->hasFile('avatar')) {
            return response()->json(['message' => 'Файл не найден в запросе'], 400);
        }

        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,jpg,png|max:2048',
        ]);

        $user = auth()->user();

        if ($request->file('avatar')->isValid()) {
            if ($user->avatar_url) {
                $oldPath = str_replace('/storage/', '', $user->avatar_url);
                Storage::disk('public')->delete($oldPath);
            }

            $path = $request->file('avatar')->store('avatars', 'public');

            $user->update([
                'avatar_url' => '/storage/'.$path,
            ]);

            \Log::info('Avatar uploaded successfully', ['path' => $path]);

            return response()->json(['success' => true, 'avatar_url' => '/storage/'.$path]);
        }

        return response()->json(['message' => 'Не удалось обработать файл.'], 400);
    }

    public function storeService(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'duration_minutes' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
        ]);

        $user->services()->create($validated);

        return back()->with('success', 'Услуга добавлена');
    }

    public function updateService(Request $request, Service $service)
    {
        $user = auth()->user();

        if ($service->user_id !== $user->id) {
            abort(403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'duration_minutes' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
        ]);

        $service->update($validated);

        return back()->with('success', 'Услуга обновлена');
    }

    public function destroyService(Service $service)
    {
        $user = auth()->user();

        if ($service->user_id !== $user->id) {
            abort(403);
        }

        $service->delete();

        return back()->with('success', 'Услуга удалена');
    }

    public function updateWorkingHours(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'working_hours' => 'required|array|min:1|max:7',
            'working_hours.*.day_of_week' => 'required|integer|min:0|max:6',
            'working_hours.*.is_working' => 'required|boolean',
            'working_hours.*.start_time' => ['nullable', 'string'],
            'working_hours.*.end_time' => ['nullable', 'string'],
            'working_hours.*.break_start_time' => ['nullable', 'string'],
            'working_hours.*.break_end_time' => ['nullable', 'string'],
            'slot_interval' => 'required|integer|in:15,30,60',
        ]);

        // Нормализация: пустые строки и артефакты масок → null
        foreach ($validated['working_hours'] as &$hour) {
            $timeFields = ['start_time', 'end_time', 'break_start_time', 'break_end_time'];
            foreach ($timeFields as $field) {
                $val = $hour[$field] ?? null;
                if ($val === '' || $val === '--:--' || $val === '00:00' && ! empty(str_replace(':', '', $val))) {
                    // оставляем 00:00 как валидное время (полночь), заменяем только мусор
                }
                if ($val === '' || $val === '--:--' || $val === '--' || $val === ':') {
                    $hour[$field] = null;
                }
            }
        }
        unset($hour);

        $errors = [];

        foreach ($validated['working_hours'] as $index => $hour) {
            if (! $hour['is_working']) {
                $user->workingHours()->updateOrCreate(
                    ['day_of_week' => $hour['day_of_week']],
                    [
                        'is_working' => false,
                        'start_time' => null,
                        'end_time' => null,
                        'break_start_time' => null,
                        'break_end_time' => null,
                    ]
                );

                continue;
            }

            $startTime = ! empty($hour['start_time']) ? $hour['start_time'] : null;
            $endTime = ! empty($hour['end_time']) ? $hour['end_time'] : null;

            if ($startTime === null || $endTime === null) {
                $errors["working_hours.{$index}.start_time"] = 'Для рабочего дня укажите время начала и окончания.';
                continue;
            }

            if (! preg_match('/^\d{2}:\d{2}$/', $startTime) || ! preg_match('/^\d{2}:\d{2}$/', $endTime)) {
                $errors["working_hours.{$index}.start_time"] = 'Неверный формат времени. Используйте ЧЧ:ММ.';
                continue;
            }

            if ($endTime <= $startTime) {
                $errors["working_hours.{$index}.end_time"] = 'Время окончания должно быть позже времени начала.';
                continue;
            }

            $breakStart = ! empty($hour['break_start_time']) ? $hour['break_start_time'] : null;
            $breakEnd = ! empty($hour['break_end_time']) ? $hour['break_end_time'] : null;
            $hasBreak = ($breakStart !== null) && ($breakEnd !== null);

            if ($hasBreak) {
                if (! preg_match('/^\d{2}:\d{2}$/', $breakStart) || ! preg_match('/^\d{2}:\d{2}$/', $breakEnd)) {
                    $errors["working_hours.{$index}.break_start_time"] = 'Неверный формат времени обеда.';
                    continue;
                }

                if ($breakStart <= $startTime) {
                    $errors["working_hours.{$index}.break_start_time"] = 'Обед должен начинаться после начала рабочего дня.';
                    continue;
                }

                if ($breakEnd >= $endTime) {
                    $errors["working_hours.{$index}.break_end_time"] = 'Обед должен заканчиваться до окончания рабочего дня.';
                    continue;
                }

                if ($breakEnd <= $breakStart) {
                    $errors["working_hours.{$index}.break_end_time"] = 'Время окончания обеда должно быть позже времени начала.';
                    continue;
                }
            }

            $user->workingHours()->updateOrCreate(
                ['day_of_week' => $hour['day_of_week']],
                [
                    'is_working' => true,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'break_start_time' => $breakStart,
                    'break_end_time' => $breakEnd,
                ]
            );
        }

        if (! empty($errors)) {
            return back()->withErrors($errors)->withInput();
        }

        $user->update(['slot_interval' => $validated['slot_interval']]);

        return back()->with('success', 'График работы обновлён');
    }

    public function storeBlockedTime(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'start_datetime' => 'required|date',
            'end_datetime' => 'required|date|after:start_datetime',
            'reason' => 'required|string|max:255',
        ]);

        $user->blockedTimes()->create($validated);

        return back()->with('success', 'Блокировка добавлена');
    }

    public function destroyBlockedTime(BlockedTime $blockedTime)
    {
        $user = auth()->user();

        if ($blockedTime->user_id !== $user->id) {
            abort(403);
        }

        $blockedTime->delete();

        return back()->with('success', 'Блокировка удалена');
    }

    public function updateTimezone(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'timezone' => ['required', 'string', function ($attribute, $value, $fail) {
                if (! in_array($value, timezone_identifiers_list(), true)) {
                    $fail('Неверный часовой пояс.');
                }
            }],
        ]);

        $user = auth()->user();
        $user->setTimezone($validated['timezone']);

        return back()->with('success', 'Часовой пояс обновлён');
    }
}
