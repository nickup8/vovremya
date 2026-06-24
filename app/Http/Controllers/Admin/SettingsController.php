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
                'telegram_notifications' => $user->telegram_notifications,
                'max_notifications' => $user->max_notifications,
            ],
            'services' => $user->services()->get(),
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
}
