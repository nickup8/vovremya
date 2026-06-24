<?php

use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\CalendarController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Auth\TelegramAuthController;
use App\Http\Controllers\BookingWidgetController;
use App\Http\Controllers\Client\BookingsController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::get('/login', fn () => redirect()->route('auth.choose'))->name('login');
Route::get('/auth/login', [TelegramAuthController::class, 'showChoose'])->name('auth.choose');
Route::get('/auth/provider/{provider}', [TelegramAuthController::class, 'loginWithProvider'])->name('auth.provider');
Route::post('/logout', [TelegramAuthController::class, 'logout'])->name('logout');

Route::get('/book/{master}', [BookingWidgetController::class, 'show'])->name('booking.widget');
Route::post('/book/{master}', [BookingWidgetController::class, 'store'])->name('booking.reserve');

Route::post('/webhooks/telegram', [WebhookController::class, 'handleTelegram'])->name('webhooks.telegram');
Route::post('/webhooks/max', [WebhookController::class, 'handleMax'])->name('webhooks.max');

Route::get('/dev/login-master', function () {
    $master = \App\Models\User::where('master_slug', 'test-master')->firstOrFail();
    Auth::login($master);

    return redirect()->route('admin.calendar');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/admin/calendar', [CalendarController::class, 'index'])->name('admin.calendar');
    Route::patch('/admin/appointments/{appointment}/status', [CalendarController::class, 'updateStatus'])->name('admin.appointments.update-status');

    Route::get('/admin/clients', [ClientController::class, 'index'])->name('admin.clients');

    Route::get('/admin/analytics', [AnalyticsController::class, 'index'])->name('admin.analytics');

    Route::get('/admin/settings', [SettingsController::class, 'index'])->name('admin.settings');
    Route::put('/admin/settings', [SettingsController::class, 'update'])->name('admin.settings.update');
    Route::post('/admin/settings/avatar', [SettingsController::class, 'updateAvatar'])->name('admin.settings.avatar');

    Route::post('/admin/services', [SettingsController::class, 'storeService'])->name('admin.services.store');
    Route::put('/admin/services/{service}', [SettingsController::class, 'updateService'])->name('admin.services.update');
    Route::delete('/admin/services/{service}', [SettingsController::class, 'destroyService'])->name('admin.services.destroy');

    Route::get('/my-bookings', [BookingsController::class, 'index'])->name('client.bookings');
    Route::patch('/my-bookings/appointments/{appointment}/cancel', [BookingsController::class, 'cancel'])->name('client.appointments.cancel');
});
