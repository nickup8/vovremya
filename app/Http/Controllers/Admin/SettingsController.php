<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function index()
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
                'soft_deposit' => $user->soft_deposit,
                'deposit_timeout' => $user->deposit_timeout,
                'deposit_percent' => $user->deposit_percent,
                'slot_interval' => $user->slot_interval,
                'telegram_notifications' => $user->telegram_notifications,
                'max_notifications' => $user->max_notifications,
            ],
            'services' => $user->services()->get(),
            'workingHours' => $user->workingHours()->get(),
            'blockedTimes' => $user->blockedTimes()->get(),
        ]);
    }

    public function update(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
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
            'soft_deposit' => 'boolean',
            'deposit_timeout' => 'nullable|integer|min:1',
            'deposit_percent' => 'nullable|integer|min:1|max:100',
            'telegram_notifications' => 'boolean',
            'max_notifications' => 'boolean',
        ]);

        $user->update($validated);

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
            'working_hours.*.start_time' => 'nullable|date_format:H:i',
            'working_hours.*.end_time' => 'nullable|date_format:H:i|after:working_hours.*.start_time',
            'working_hours.*.break_start_time' => 'nullable|date_format:H:i',
            'working_hours.*.break_end_time' => 'nullable|date_format:H:i',
            'slot_interval' => 'required|integer|in:15,30,60',
        ]);

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

            if (empty($hour['start_time']) || empty($hour['end_time'])) {
                return back()->withErrors([
                    "working_hours.{$index}.start_time" => 'Для рабочего дня обязательно укажите время начала и окончания.',
                ])->withInput();
            }

            $hasBreak = ! empty($hour['break_start_time']) || ! empty($hour['break_end_time']);

            if ($hasBreak) {
                if (empty($hour['break_start_time']) || empty($hour['break_end_time'])) {
                    return back()->withErrors([
                        "working_hours.{$index}.break_start_time" => 'Если указано время начала обеда, необходимо указать и время окончания.',
                    ])->withInput();
                }

                if ($hour['break_start_time'] <= $hour['start_time']) {
                    return back()->withErrors([
                        "working_hours.{$index}.break_start_time" => 'Обед должен начинаться после начала рабочего дня.',
                    ])->withInput();
                }

                if ($hour['break_end_time'] >= $hour['end_time']) {
                    return back()->withErrors([
                        "working_hours.{$index}.break_end_time" => 'Обед должен заканчиваться до окончания рабочего дня.',
                    ])->withInput();
                }

                if ($hour['break_end_time'] <= $hour['break_start_time']) {
                    return back()->withErrors([
                        "working_hours.{$index}.break_end_time" => 'Время окончания обеда должно быть позже времени начала.',
                    ])->withInput();
                }
            }

            $user->workingHours()->updateOrCreate(
                ['day_of_week' => $hour['day_of_week']],
                [
                    'is_working' => true,
                    'start_time' => $hour['start_time'],
                    'end_time' => $hour['end_time'],
                    'break_start_time' => $hasBreak ? $hour['break_start_time'] : null,
                    'break_end_time' => $hasBreak ? $hour['break_end_time'] : null,
                ]
            );
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

    public function destroyBlockedTime(\App\Models\BlockedTime $blockedTime)
    {
        $user = auth()->user();

        if ($blockedTime->user_id !== $user->id) {
            abort(403);
        }

        $blockedTime->delete();

        return back()->with('success', 'Блокировка удалена');
    }
}
