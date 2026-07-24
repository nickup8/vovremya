<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class MagicLoginController extends Controller
{
    public function __invoke(Request $request)
    {
        $token = $request->query('token');

        if (! $token) {
            return redirect('/')->with('error', 'Неверная ссылка авторизации.');
        }

        $userId = Cache::pull('magic_login_' . $token);

        if (! $userId) {
            return redirect('/')->with('error', 'Ссылка устарела или уже была использована. Пожалуйста, авторизуйтесь заново.');
        }

        $user = User::find($userId);

        if (! $user) {
            return redirect('/')->with('error', 'Пользователь не найден.');
        }

        Auth::login($user, true);

        return redirect($user->is_master ? '/admin/calendar' : '/client/bookings');
    }
}
