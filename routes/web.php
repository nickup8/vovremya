<?php

use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\CalendarController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SuperAdminController;
use App\Http\Controllers\Auth\TelegramAuthController;
use App\Http\Controllers\BookingStatusController;
use App\Http\Controllers\BookingWidgetController;
use App\Http\Controllers\Client\BookingsController;
use App\Http\Controllers\Client\ClientAuthController;
use App\Http\Controllers\ClientModeController;
use App\Http\Controllers\Webhook\PaymentWebhookController;
use App\Http\Controllers\WebhookController;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::get('/login', fn () => redirect()->route('auth.choose'))->name('login');
Route::get('/auth/login', [TelegramAuthController::class, 'showChoose'])->name('auth.choose');
Route::get('/auth/provider/{provider}', [TelegramAuthController::class, 'loginWithProvider'])->name('auth.provider');
Route::post('/logout', [TelegramAuthController::class, 'logout'])->name('logout');

Route::get('/book/{master}', [BookingWidgetController::class, 'show'])->name('booking.widget');
Route::post('/book/{master}', [BookingWidgetController::class, 'store'])->middleware('throttle:5,1')->name('booking.reserve');
Route::get('/book/status/{id}', [BookingStatusController::class, 'show'])->name('booking.status');

Route::post('/webhooks/telegram', [WebhookController::class, 'handleTelegram'])->middleware('throttle:60,1')->name('webhooks.telegram');
Route::post('/webhooks/max', [WebhookController::class, 'handleMax'])->middleware('throttle:60,1')->name('webhooks.max');

Route::get('/client/auth/{token}', [ClientAuthController::class, 'loginByToken'])->name('client.login');
Route::post('/client/logout', [ClientAuthController::class, 'logout'])->name('client.logout');

if (app()->environment('local')) {
    Route::get('/dev/login-master', function () {
        $master = User::where('master_slug', 'test-master')->firstOrFail();
        Auth::login($master);

        return redirect()->route('admin.calendar');
    });
}

Route::middleware(['auth'])->group(function () {
    Route::get('/admin/calendar', [CalendarController::class, 'index'])->name('admin.calendar');
    Route::post('/admin/calendar/appointments', [CalendarController::class, 'store'])->name('admin.calendar.store');
    Route::patch('/admin/appointments/{appointment}/status', [CalendarController::class, 'updateStatus'])->name('admin.appointments.update-status');

    Route::get('/admin/clients', [ClientController::class, 'index'])->name('admin.clients');
    Route::post('/admin/clients', [ClientController::class, 'store'])->name('admin.clients.store');
    Route::put('/admin/clients/{client}', [ClientController::class, 'update'])->name('admin.clients.update');
    Route::post('/admin/clients/{client}/toggle-block', [ClientController::class, 'toggleBlock'])->name('admin.clients.toggle-block');

    Route::get('/admin/analytics', [AnalyticsController::class, 'index'])->name('admin.analytics');

    Route::get('/admin/settings', [SettingsController::class, 'index'])->name('admin.settings');
    Route::put('/admin/settings', [SettingsController::class, 'update'])->name('admin.settings.update');
    Route::patch('/admin/settings/timezone', [SettingsController::class, 'updateTimezone'])->name('admin.settings.timezone');
    Route::post('/admin/settings/avatar', [SettingsController::class, 'updateAvatar'])->name('admin.settings.avatar');

    Route::post('/admin/services', [SettingsController::class, 'storeService'])->name('admin.services.store');
    Route::put('/admin/services/{service}', [SettingsController::class, 'updateService'])->name('admin.services.update');
    Route::delete('/admin/services/{service}', [SettingsController::class, 'destroyService'])->name('admin.services.destroy');

    Route::put('/admin/working-hours', [SettingsController::class, 'updateWorkingHours'])->name('admin.working-hours.update');
    Route::post('/admin/blocked-times', [SettingsController::class, 'storeBlockedTime'])->name('admin.blocked-times.store');
    Route::delete('/admin/blocked-times/{blockedTime}', [SettingsController::class, 'destroyBlockedTime'])->name('admin.blocked-times.destroy');

    Route::post('/admin/checkout', [PaymentController::class, 'createCheckout'])->name('admin.checkout');
});

Route::post('/webhooks/payment', [PaymentWebhookController::class, 'handle'])->middleware('throttle:60,1')->name('webhooks.payment');

Route::middleware(['auth', 'super_admin'])->prefix('admin-root')->group(function () {
    Route::get('/', [SuperAdminController::class, 'index'])->name('super_admin.dashboard');
    Route::get('/users', [SuperAdminController::class, 'users'])->name('super_admin.users');
    Route::post('/users/{user}/block', [SuperAdminController::class, 'blockUser'])->name('super_admin.block');
    Route::post('/users/{user}/extend', [SuperAdminController::class, 'extendSubscription'])->name('super_admin.extend');
    Route::post('/users/{user}/impersonate', [SuperAdminController::class, 'impersonate'])->name('super_admin.impersonate');
});

Route::middleware(['auth'])->group(function () {
    Route::post('/client-mode/enable', [ClientModeController::class, 'enable'])->name('client_mode.enable');
    Route::post('/client-mode/disable', [ClientModeController::class, 'disable'])->name('client_mode.disable');
});

Route::middleware(['auth:client'])->group(function () {
    Route::get('/my-bookings', [BookingsController::class, 'index'])->name('client.bookings');
    Route::patch('/my-bookings/appointments/{appointment}/cancel', [BookingsController::class, 'cancel'])->name('client.appointments.cancel');
});
