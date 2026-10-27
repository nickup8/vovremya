<?php

namespace App\Http\Controllers\Client;

use App\Exceptions\AmbiguousClientMatchException;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use App\Services\Client\ClientMergeService;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RoleSwitchController extends Controller
{
    /**
     * Мастер → Клиент.
     * Находит (или создаёт) Client-запись по телефону мастера и логинит в guard 'client'.
     */
    public function toClient(Request $request, ClientMergeService $clientMerge): RedirectResponse
    {
        $user = $request->user();

        if (! $user || ! $user->is_master) {
            return back();
        }

        if (! $user->phone) {
            return back()->with('error', 'Укажите номер телефона в профиле для входа в режим клиента.');
        }

        try {
            $client = $clientMerge->findOrCreateByPhone(
                $user->id,
                $user->phone,
                '',
                $user->name,
                $user->workspace_id,
            );
        } catch (AmbiguousClientMatchException) {
            return back()->with('error', 'Найдено несколько карточек клиентов с вашим номером. Обратитесь в поддержку.');
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

        $normalized = Phone::normalize($client->phone);

        $user = $normalized !== null
            ? User::where('phone', $normalized)->first()
            : null;

        if (! $user && $client->phone) {
            $user = User::where('phone', $client->phone)->first();
        }

        if (! $user) {
            return redirect()->route('auth.choose')
                ->with('info', 'Для перехода в режим мастера необходимо создать профиль.');
        }

        Auth::guard('web')->login($user);

        return redirect()->route('admin.calendar');
    }
}
