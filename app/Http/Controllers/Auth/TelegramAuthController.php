<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TelegramAuthController extends Controller
{
    public function showChoose()
    {
        return Inertia::render('auth/choose');
    }

    public function loginWithProvider(string $provider)
    {
        if (! in_array($provider, ['telegram', 'max'])) {
            return redirect('/');
        }

        $randomId = rand(100, 999);

        $telegramId = $provider === 'telegram' ? 'tg_'.$randomId : null;
        $maxId = $provider === 'max' ? 'max_'.$randomId : null;

        $user = User::create([
            'name' => 'Мастер ('.ucfirst($provider).' #'.$randomId.')',
            'telegram_id' => $telegramId,
            'max_id' => $maxId,
            'is_master' => true,
            'master_slug' => 'master-'.$randomId,
            'specialty' => 'Бьюти-мастер',
            'address' => 'Адрес студии #'.$randomId,
        ]);

        auth()->login($user);

        return redirect()->route('admin.calendar')
            ->with('success', 'Успешно зарегистрирован через '.$provider);
    }

    public function logout(Request $request)
    {
        auth()->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
