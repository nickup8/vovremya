<?php

use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\CalendarController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SuperAdminController;
use App\Http\Controllers\Admin\TeamController;
use App\Http\Controllers\Auth\TelegramAuthController;
use App\Http\Controllers\BookingStatusController;
use App\Http\Controllers\BookingWidgetController;
use App\Http\Controllers\Client\BookingsController;
use App\Http\Controllers\Client\ClientAuthController;
use App\Http\Controllers\Client\ClientProfileController;
use App\Http\Controllers\Client\RoleSwitchController;
use App\Http\Controllers\ClientModeController;
use App\Http\Controllers\Webhook\PaymentWebhookController;
use App\Http\Controllers\Webhook\TelegraphWebhookController;
use App\Http\Controllers\WebhookController;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    if (Auth::check()) {
        return redirect()->route('admin.calendar');
    }

    return Inertia::render('welcome');
})->name('home');

Route::get('/login', fn () => redirect()->route('auth.choose'))->name('login');
Route::get('/auth/login', [TelegramAuthController::class, 'showChoose'])->name('auth.choose');
Route::post('/auth/telegram/token', [TelegramAuthController::class, 'generateLoginToken'])->name('auth.telegram.token');
Route::get('/auth/telegram/check/{token}', [TelegramAuthController::class, 'checkAuthStatus'])->middleware('throttle:30,1')->name('auth.telegram.check');
Route::post('/logout', [TelegramAuthController::class, 'logout'])->name('logout');

Route::get('/book/{master}', [BookingWidgetController::class, 'show'])->name('booking.widget');
Route::get('/book/{master}/available-dates', [BookingWidgetController::class, 'availableDates'])->name('booking.available-dates');
Route::post('/book/{master}', [BookingWidgetController::class, 'store'])->middleware('throttle:5,1')->name('booking.reserve');
Route::get('/book/status/{id}', [BookingStatusController::class, 'show'])->name('booking.status');

Route::post('/webhooks/telegram', [WebhookController::class, 'handleTelegram'])->middleware('throttle:60,1')->name('webhooks.telegram');
Route::post('/webhooks/telegram/bypass', [WebhookController::class, 'handleBypass'])->middleware('throttle:60,1')->name('webhooks.telegram.bypass');
Route::post('/webhooks/max', [WebhookController::class, 'handleMax'])->middleware('throttle:60,1')->name('webhooks.max');
Route::post('/max/webhook', [WebhookController::class, 'handleMax'])->middleware('throttle:60,1')->name('max.webhook');

// Диагностический маршрут для перехвата вебхука Telegraph с логированием токена.
// Переопределяет авто-регистрируемый маршрут пакета (/telegraph/{token}/webhook),
// чтобы мы видели в логах, что именно приходит от Telegram.
Route::post('/telegraph/{token}/webhook', [TelegraphWebhookController::class, 'handle'])
    ->middleware('throttle:120,1')
    ->name('telegraph.webhook.debug');

// Временный роут для принудительной перерегистрации вебхука (только dev)
if (app()->environment('local')) {
    Route::get('/dev/set-webhook', function () {
        $bot = \DefStudio\Telegraph\Models\TelegraphBot::first();

        if (! $bot) {
            return 'Бот не найден в базе.';
        }

        $url = 'https://catchy-suitably-hacked.ngrok-free.dev/webhooks/telegram/bypass';
        $secretToken = config('services.telegram.secret_token');

        $registration = $bot->registerWebhook()->url($url);

        if ($secretToken) {
            $registration->secretToken($secretToken);
        }

        $registration->send();

        return 'Webhook forced to: ' . $url;
    });
}

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
    Route::delete('/admin/settings/avatar', [SettingsController::class, 'destroyAvatar'])->name('admin.settings.avatar.destroy');

    Route::post('/admin/services', [SettingsController::class, 'storeService'])->name('admin.services.store');
    Route::put('/admin/services/{service}', [SettingsController::class, 'updateService'])->name('admin.services.update');
    Route::delete('/admin/services/{service}', [SettingsController::class, 'destroyService'])->name('admin.services.destroy');

    Route::put('/admin/working-hours', [SettingsController::class, 'updateWorkingHours'])->name('admin.working-hours.update');
    Route::post('/admin/blocked-times', [SettingsController::class, 'storeBlockedTime'])->name('admin.blocked-times.store');
    Route::delete('/admin/blocked-times/{blockedTime}', [SettingsController::class, 'destroyBlockedTime'])->name('admin.blocked-times.destroy');

    Route::post('/admin/checkout', [PaymentController::class, 'createCheckout'])->name('admin.checkout');

    Route::get('/admin/team', [TeamController::class, 'index'])->name('admin.team');
    Route::post('/admin/team/invite', [TeamController::class, 'generateInvite'])->name('admin.team.invite');
});

Route::post('/webhooks/payment', [PaymentWebhookController::class, 'handle'])->middleware('throttle:60,1')->name('webhooks.payment');

Route::middleware(['auth', 'super_admin'])->prefix('admin-root')->group(function () {
    Route::get('/', [SuperAdminController::class, 'index'])->name('super_admin.dashboard');
    Route::get('/users', [SuperAdminController::class, 'users'])->name('super_admin.users');
    Route::post('/users/{user}/block', [SuperAdminController::class, 'blockUser'])->name('super_admin.block');
    Route::post('/users/{user}/extend', [SuperAdminController::class, 'extendSubscription'])->name('super_admin.extend');
    Route::post('/users/{user}/impersonate', [SuperAdminController::class, 'impersonate'])->name('super_admin.impersonate');
    Route::post('/leave-impersonate', [SuperAdminController::class, 'leaveImpersonate'])->name('super_admin.leave_impersonate');
});

Route::middleware(['auth'])->group(function () {
    Route::post('/switch-to-client', [RoleSwitchController::class, 'toClient'])->name('switch.to.client');

    Route::post('/client-mode/enable', [ClientModeController::class, 'enable'])->name('client_mode.enable');
    Route::post('/client-mode/disable', [ClientModeController::class, 'disable'])->name('client_mode.disable');
});

Route::middleware(['auth:client'])->prefix('client')->group(function () {
    Route::post('/switch-to-master', [RoleSwitchController::class, 'toMaster'])->name('switch.to.master');
    Route::get('/my-profile', [ClientProfileController::class, 'index'])->name('client.profile');
    Route::get('/my-bookings', [BookingsController::class, 'index'])->name('client.bookings');
    Route::patch('/my-bookings/appointments/{appointment}/cancel', [BookingsController::class, 'cancel'])->name('client.appointments.cancel');
});
