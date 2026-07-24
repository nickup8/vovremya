<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

class MagicLoginController extends Controller
{
    public function show(Request $request)
    {
        $token = $request->query('token');

        if (! $token) {
            return redirect('/')->with('error', 'Неверная ссылка авторизации.');
        }

        $userId = Cache::get('magic_login_' . $token);

        if (! $userId) {
            return redirect('/')->with('error', 'Ссылка устарела или уже была использована.');
        }

        return Inertia::render('auth/magic-confirm', ['token' => $token]);
    }

    public function login(Request $request)
    {
        $token = $request->input('token');

        if (! $token) {
            return redirect('/')->with('error', 'Неверная ссылка авторизации.');
        }

        $userId = Cache::pull('magic_login_' . $token);

        if (! $userId) {
            return redirect('/')->with('error', 'Ссылка устарела или уже была использована.');
        }

        $user = User::find($userId);

        if (! $user) {
            return redirect('/')->with('error', 'Пользователь не найден.');
        }

        Auth::login($user, true);

        return redirect($user->is_master ? '/admin/calendar' : '/client/bookings');
    }
}
