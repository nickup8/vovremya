<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ClientAuthController extends Controller
{
    /**
     * Авторизация клиента по токену из ссылки бота.
     * URL: /client/auth/{token}
     *
     * Логика:
     * 1. Находим клиента по auth_token.
     * 2. Логиним его в guard 'client'.
     * 3. Редиректим на /my-bookings.
     * 4. Токен одноразовый — удаляем после входа.
     */
    public function loginByToken(string $token): RedirectResponse
    {
        $client = Client::where('auth_token', $token)->first();

        if (! $client) {
            return redirect()->route('home')->with('error', 'Ссылка недействительна или уже использована.');
        }

        Auth::guard('client')->login($client);

        $client->update(['auth_token' => null]);

        return redirect()->route('client.profile');
    }

    /**
     * Выход клиента из сессии.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('client')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
