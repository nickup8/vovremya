<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\BlockedTime;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class SettingsController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $user = auth()->user();
        $isAdminOrOwner = $user->role->canManageTeam();

        // Определяем target-мастера
        if ($isAdminOrOwner) {
            $targetMasterId = $request->query('master_id');
            if ($targetMasterId) {
                $targetMaster = User::where('id', $targetMasterId)
                    ->where('workspace_id', $user->workspace_id)
                    ->where('is_master', true)
                    ->firstOrFail();
            } else {
                $targetMaster = $user;
            }

            $masters = $user->workspace->users()
                ->where('is_master', true)
                ->select('id', 'name')
                ->get();
        } else {
            $targetMaster = $user;
            $masters = [];
        }

        // Генерация токена для привязки Telegram, если он еще не создан
        if (! $user->telegram_chat_id && ! $user->telegram_auth_token) {
            $user->update([
                'telegram_auth_token' => Str::random(32),
            ]);
            $user->refresh();
        }

        // Deep link для привязки Telegram
        $tgBotName = config('services.telegram.bot_name');
        $telegramLinkUrl = $user->telegram_chat_id
            ? null
            : "https://t.me/{$tgBotName}?start={$user->telegram_auth_token}";

        // Deep link для привязки MAX
        $maxBotName = config('services.max.bot_name');
        $maxLinkUrl = null;
        if (! $user->max_id && $maxBotName) {
            $linkToken = 'link_' . Str::uuid();
            Cache::put("max_link:{$linkToken}", $user->id, now()->addMinutes(60));
            $maxLinkUrl = "https://max.ru/{$maxBotName}?start={$linkToken}";
        }

        return Inertia::render('admin/settings', [
            'profile' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'master_slug' => $user->master_slug,
                'specialty' => $user->specialty,
                'address' => $user->address,
                'avatar_url' => $user->avatar_url,
                'telegram_id' => $user->telegram_id,
                'telegram_chat_id' => $user->telegram_chat_id,
                'telegram_auth_token' => $user->telegram_auth_token,
                'telegram_bot_name' => $tgBotName,
                'telegram_link_url' => $telegramLinkUrl,
                'max_id' => $user->max_id,
                'max_link_url' => $maxLinkUrl,
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
            'services' => $targetMaster->services()->get(),
            'workingHours' => $targetMaster->workingHours()->get(),
            'blockedTimes' => $targetMaster->blockedTimes()->get(),
            'masters' => $masters,
            'selectedMasterId' => $targetMaster->id,
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
            'reminder_hours_before_final' => ['nullable', 'integer', Rule::in([0, 1, 2, 3, 12])],
        ];

        $sentFields = $request->only(array_keys($allRules));
        $activeRules = array_intersect_key($allRules, $sentFields);

        if (empty($activeRules)) {
            return back()->with('error', 'Нет данных для обновления');
        }

        $validated = $request->validate($activeRules);

        unset($validated['role'], $validated['workspace_id'], $validated['is_master']);

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

    public function destroyAvatar(): RedirectResponse
    {
        $user = auth()->user();

        if ($user->avatar_url) {
            $path = str_replace('/storage/', '', $user->avatar_url);
            Storage::disk('public')->delete($path);
            $user->update(['avatar_url' => null]);
        }

        return back()->with('success', 'Фото удалено');
    }

    public function storeService(Request $request)
    {
        $user = auth()->user();
        $isAdminOrOwner = $user->role->canManageTeam();

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'duration_minutes' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'master_id' => 'nullable|uuid|exists:users,id',
        ]);

        if ($isAdminOrOwner && ! empty($validated['master_id'])) {
            $targetMaster = User::where('id', $validated['master_id'])
                ->where('workspace_id', $user->workspace_id)
                ->where('is_master', true)
                ->firstOrFail();
        } else {
            $targetMaster = $user;
        }

        unset($validated['master_id']);

        $targetMaster->services()->create($validated);

        return back()->with('success', 'Услуга добавлена');
    }

    public function updateService(Request $request, Service $service)
    {
        $this->authorize('update', $service);

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
        $this->authorize('delete', $service);

        $service->delete();

        return back()->with('success', 'Услуга удалена');
    }

    public function updateWorkingHours(Request $request)
    {
        $user = auth()->user();
        $isAdminOrOwner = $user->role->canManageTeam();

        $validated = $request->validate([
            'working_hours' => 'required|array|min:1|max:7',
            'working_hours.*.day_of_week' => 'required|integer|min:0|max:6',
            'working_hours.*.is_working' => 'required|boolean',
            'working_hours.*.start_time' => ['nullable', 'string', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'working_hours.*.end_time' => ['nullable', 'string', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'working_hours.*.break_start_time' => ['nullable', 'string', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'working_hours.*.break_end_time' => ['nullable', 'string', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'slot_interval' => 'required|integer|in:15,30,60',
            'master_id' => 'nullable|uuid|exists:users,id',
        ]);

        if ($isAdminOrOwner && ! empty($validated['master_id'])) {
            $targetMaster = User::where('id', $validated['master_id'])
                ->where('workspace_id', $user->workspace_id)
                ->where('is_master', true)
                ->firstOrFail();
        } else {
            $targetMaster = $user;
        }

        unset($validated['master_id']);

        $errors = [];

        foreach ($validated['working_hours'] as $index => $hour) {
            if (! $hour['is_working']) {
                $targetMaster->workingHours()->updateOrCreate(
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

            if (! preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime) || ! preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $endTime)) {
                $errors["working_hours.{$index}.start_time"] = 'Неверный формат времени. Используйте ЧЧ:ММ или ЧЧ:ММ:СС.';
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
                if (! preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $breakStart) || ! preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $breakEnd)) {
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

            $targetMaster->workingHours()->updateOrCreate(
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

        $targetMaster->update(['slot_interval' => $validated['slot_interval']]);

        return back()->with('success', 'График работы обновлён');
    }

    public function storeBlockedTime(Request $request)
    {
        $user = auth()->user();
        $isAdminOrOwner = $user->role->canManageTeam();

        $validated = $request->validate([
            'start_datetime' => 'required|date',
            'end_datetime' => 'required|date|after:start_datetime',
            'reason' => ['required', Rule::in(array_column(\App\Enums\BlockedTimeReason::cases(), 'value'))],
            'master_id' => 'nullable|uuid|exists:users,id',
        ]);

        if ($isAdminOrOwner && ! empty($validated['master_id'])) {
            $targetMaster = User::where('id', $validated['master_id'])
                ->where('workspace_id', $user->workspace_id)
                ->where('is_master', true)
                ->firstOrFail();
        } else {
            $targetMaster = $user;
        }

        unset($validated['master_id']);

        $targetMaster->blockedTimes()->create($validated);

        return back()->with('success', 'Блокировка добавлена');
    }

    public function destroyBlockedTime(BlockedTime $blockedTime)
    {
        $user = auth()->user();

        if ($user->role->canManageTeam()) {
            abort_unless(
                $blockedTime->user->workspace_id === $user->workspace_id,
                403,
                'Блокировка принадлежит другому workspace.'
            );
        } else {
            abort_unless($blockedTime->user_id === $user->id, 403);
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
