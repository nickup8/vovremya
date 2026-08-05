<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RoleSwitchController extends Controller
{
    /**
     * Мастер → Клиент.
     * Находит (или создаёт) Client-запись по телефону мастера и логинит в guard 'client'.
     */
    public function toClient(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user || ! $user->is_master) {
            return back();
        }

        if (! $user->phone) {
            return back()->with('error', 'Укажите номер телефона в профиле для входа в режим клиента.');
        }

        // Ищем существующего клиента по user_id + phone
        $client = Client::where('user_id', $user->id)
            ->where('phone', $user->phone)
            ->first();

        // Если не нашли и есть workspace_id — проверяем partial unique (workspace_id, phone)
        if (! $client && $user->workspace_id) {
            $client = Client::where('workspace_id', $user->workspace_id)
                ->where('phone', $user->phone)
                ->first();

            if ($client) {
                $client->update(['user_id' => $user->id]);
            }
        }

        if (! $client) {
            $client = Client::create([
                'user_id' => $user->id,
                'workspace_id' => $user->workspace_id,
                'name' => $user->name,
                'phone' => $user->phone,
            ]);
        }

        Auth::guard('client')->login($client);

        return redirect()->route('client.profile');
    }

    /**
     * Клиент → Мастер.
     * Находит User-запись по телефону клиента и логинит в guard 'web'.
     */
    public function toMaster(Request $request): RedirectResponse
    {
        $client = $request->user();

        if (! $client) {
            return redirect()->route('auth.choose');
        }

        $user = User::where('phone', $client->phone)->first();

        if (! $user) {
            return redirect()->route('auth.choose')
                ->with('info', 'Для перехода в режим мастера необходимо создать профиль.');
        }

        Auth::guard('web')->login($user);

        return redirect()->route('admin.calendar');
    }
}
