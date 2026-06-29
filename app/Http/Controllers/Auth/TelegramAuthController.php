<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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

        $baseName = ucfirst($provider).' Master';
        $slug = Str::slug($baseName);

        while (User::where('master_slug', $slug)->exists()) {
            $slug = Str::slug($baseName.'-'.Str::random(6));
        }

        $telegramId = $provider === 'telegram' ? 'tg_'.Str::random(10) : null;
        $maxId = $provider === 'max' ? 'max_'.Str::random(10) : null;

        $user = User::create([
            'name' => $baseName,
            'telegram_id' => $telegramId,
            'max_id' => $maxId,
            'is_master' => true,
            'master_slug' => $slug,
            'specialty' => 'Бьюти-мастер',
            'address' => 'Адрес студии',
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
