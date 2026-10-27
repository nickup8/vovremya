<?php

namespace App\Http\Controllers\Client;

use App\Exceptions\AmbiguousClientMatchException;
use App\Exceptions\DuplicateClientPhoneException;
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
        } catch (DuplicateClientPhoneException) {
            return back()->with('error', 'Клиент с таким номером телефона уже существует.');
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

        if (! $user && $normalized !== null) {
            // Тот же номер в допустимых формах хранения users.phone
            // (например, «+7…» при канонической карточке «7…»):
            // контракт — совпадение номера, а не строки; несколько
            // аккаунтов — однозначный отказ без выбора первого попавшегося.
            $matches = User::whereIn('phone', Phone::storageVariants($normalized))->get();

            if ($matches->count() > 1) {
                return redirect()->route('auth.choose')
                    ->with('info', 'Найдено несколько аккаунтов с вашим номером. Обратитесь в поддержку.');
            }

            $user = $matches->first();
        }

        if (! $user) {
            return redirect()->route('auth.choose')
                ->with('info', 'Для перехода в режим мастера необходимо создать профиль.');
        }

        Auth::guard('web')->login($user);

        return redirect()->route('admin.calendar');
    }
}
