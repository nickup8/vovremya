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

        $client = Client::where('user_id', $user->id)
            ->where('phone', $user->phone)
            ->first();

        if (! $client) {
            $client = Client::create([
                'user_id' => $user->id,
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
