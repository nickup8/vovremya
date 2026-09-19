<?php

use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\CalendarApiController;
use App\Http\Controllers\Admin\CalendarController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PlatformAdminController;
use App\Http\Controllers\Admin\ServiceCatalogController;
use App\Http\Controllers\Admin\ScheduleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SuperAdminController;
use App\Http\Controllers\Admin\TrackingLinkController;
use App\Http\Controllers\Auth\MagicLoginController;
use App\Http\Controllers\Auth\TelegramAuthController;
use App\Http\Controllers\Auth\VkAuthController;
use App\Http\Controllers\BookingWidgetController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\Client\BookingsController;
use App\Http\Controllers\Client\ClientAuthController;
use App\Http\Controllers\Client\ClientProfileController;
use App\Http\Controllers\Client\RoleSwitchController;
use App\Http\Controllers\ClientModeController;
use App\Http\Controllers\Webhook\PaymentWebhookController;
use App\Http\Controllers\Webhook\TelegraphWebhookController;
use App\Http\Controllers\Webhook\VkWebhookController;
use App\Http\Controllers\WebhookController;
use App\Models\User;
use DefStudio\Telegraph\Models\TelegraphBot;
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
Route::post('/auth/vk/start', [VkAuthController::class, 'start'])->name('auth.vk.start');
Route::get('/auth/vk/callback', [VkAuthController::class, 'callback'])->middleware('throttle:10,1')->name('auth.vk.callback');
Route::post('/logout', [TelegramAuthController::class, 'logout'])->name('logout');

Route::get('/auth/magic', [MagicLoginController::class, 'show'])
    ->name('auth.magic')
    ->middleware('throttle:30,1');

Route::post('/auth/magic', [MagicLoginController::class, 'login'])
    ->name('auth.magic.login')
    ->middleware('throttle:10,1');

Route::get('/r/{token}', [BookingWidgetController::class, 'redirect'])->name('tracking-link.redirect');
Route::get('/book/{master}', [BookingWidgetController::class, 'show'])->name('booking.widget');
Route::get('/book/{master}/available-dates', [BookingWidgetController::class, 'availableDates'])->name('booking.available-dates');
Route::post('/book/{master}', [BookingWidgetController::class, 'store'])->middleware('throttle:5,1')->name('booking.reserve');

Route::get('/offer', [LegalController::class, 'offer'])->name('legal.offer');
Route::get('/privacy', [LegalController::class, 'privacy'])->name('legal.privacy');

Route::post('/webhooks/telegram', [WebhookController::class, 'handleTelegram'])->middleware('throttle:60,1')->name('webhooks.telegram');
Route::post('/webhooks/telegram/bypass', [WebhookController::class, 'handleBypass'])->middleware('throttle:60,1')->name('webhooks.telegram.bypass');
Route::post('/webhooks/max', [WebhookController::class, 'handleMax'])->middleware('throttle:60,1')->name('webhooks.max');
Route::post('/max/webhook', [WebhookController::class, 'handleMax'])->middleware('throttle:60,1')->name('max.webhook');
Route::post('/webhooks/vk', VkWebhookController::class)->middleware('throttle:60,1')->name('webhooks.vk');

// Диагностический маршрут для перехвата вебхука Telegraph с логированием токена.
// Переопределяет авто-регистрируемый маршрут пакета (/telegraph/{token}/webhook),
// чтобы мы видели в логах, что именно приходит от Telegram.
Route::post('/telegraph/{token}/webhook', [TelegraphWebhookController::class, 'handle'])
    ->middleware('throttle:120,1')
    ->name('telegraph.webhook.debug');

// Временный роут для принудительной перерегистрации вебхука (только dev)
if (app()->environment('local')) {
    Route::get('/dev/set-webhook', function () {
        $bot = TelegraphBot::first();

        if (! $bot) {
            return 'Бот не найден в базе.';
        }

        $url = config('services.telegram.dev_webhook_url', 'https://localhost/webhooks/telegram/bypass');
        $secretToken = config('services.telegram.secret_token');

        $registration = $bot->registerWebhook()->url($url);

        if ($secretToken) {
            $registration->secretToken($secretToken);
        }

        $registration->send();

        return 'Webhook forced to: '.$url;
    });
}

// MINI-APP MAX — standalone SPA (не Inertia, авторизация через initData на API)
Route::get('/max-app', fn () => view('max-app'))->name('max-app');

// MINI-APP VK — standalone SPA (авторизация через VK launch params)
Route::get('/vk-app', fn () => view('vk-app'))->name('vk-app');

Route::get('/client/auth/{token}', [ClientAuthController::class, 'loginByToken'])->name('client.login');
Route::get('/client/link-expired', [ClientAuthController::class, 'linkExpired'])->name('client.login.expired');
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
    Route::get('/admin/calendar/data', [CalendarApiController::class, 'range'])->name('admin.calendar.data');
    Route::get('/admin/calendar/available-slots', [CalendarApiController::class, 'availableSlots'])->name('admin.calendar.available-slots');
    Route::post('/admin/calendar/appointments', [CalendarController::class, 'store'])->name('admin.calendar.store');
    Route::patch('/admin/appointments/{appointment}/status', [CalendarController::class, 'updateStatus'])->name('admin.appointments.update-status');

    Route::get('/admin/clients', [ClientController::class, 'index'])->name('admin.clients');
    Route::post('/admin/clients', [ClientController::class, 'store'])->name('admin.clients.store');
    Route::put('/admin/clients/{client}', [ClientController::class, 'update'])->name('admin.clients.update');
    Route::post('/admin/clients/{client}/toggle-block', [ClientController::class, 'toggleBlock'])->name('admin.clients.toggle-block');

    Route::get('/admin/analytics', [AnalyticsController::class, 'index'])->name('admin.analytics');

    // Управление tracking-ссылками — только ПРОФИ (feature gate). Delete отсутствует намеренно.
    Route::middleware('feature:channel_analytics')->group(function () {
        Route::post('/admin/tracking-links', [TrackingLinkController::class, 'store'])->name('admin.tracking-links.store');
        Route::put('/admin/tracking-links/{trackingLink}', [TrackingLinkController::class, 'update'])->name('admin.tracking-links.update');
        Route::patch('/admin/tracking-links/{trackingLink}/active', [TrackingLinkController::class, 'setActive'])->name('admin.tracking-links.active');
    });

    Route::get('/admin/schedule', [ScheduleController::class, 'index'])->name('admin.schedule');

    Route::get('/admin/settings', [SettingsController::class, 'index'])->name('admin.settings');
    Route::put('/admin/settings', [SettingsController::class, 'update'])->name('admin.settings.update');
    Route::put('/admin/settings/booking', [SettingsController::class, 'updateBooking'])->name('admin.settings.booking.update');
    Route::patch('/admin/settings/timezone', [SettingsController::class, 'updateTimezone'])->name('admin.settings.timezone');
    Route::post('/admin/settings/avatar', [SettingsController::class, 'updateAvatar'])->name('admin.settings.avatar');
    Route::delete('/admin/settings/avatar', [SettingsController::class, 'destroyAvatar'])->name('admin.settings.avatar.destroy');

    Route::get('/admin/catalog', [ServiceCatalogController::class, 'index'])->name('admin.catalog.index');
    Route::post('/admin/catalog', [ServiceCatalogController::class, 'store'])->name('admin.catalog.store');
    Route::put('/admin/catalog/{catalog}', [ServiceCatalogController::class, 'update'])->name('admin.catalog.update');
    Route::delete('/admin/catalog/{catalog}', [ServiceCatalogController::class, 'destroy'])->name('admin.catalog.destroy');
    Route::post('/admin/catalog/{catalog}/toggle-active', [ServiceCatalogController::class, 'toggleActive'])->name('admin.catalog.toggle-active');

    Route::put('/admin/working-hours', [SettingsController::class, 'updateWorkingHours'])->name('admin.working-hours.update');
    Route::post('/admin/blocked-times', [SettingsController::class, 'storeBlockedTime'])->name('admin.blocked-times.store');
    Route::delete('/admin/blocked-times/{blockedTime}', [SettingsController::class, 'destroyBlockedTime'])->name('admin.blocked-times.destroy');

    // Recurring blocked times — только ПРОФИ (feature gate)
    Route::middleware('feature:recurring_blocked_times')->group(function () {
        Route::post('/admin/recurring-blocked-times/preview', [\App\Http\Controllers\Admin\RecurringBlockedTimeController::class, 'preview'])->name('admin.recurring-blocked-times.preview');
        Route::post('/admin/recurring-blocked-times', [\App\Http\Controllers\Admin\RecurringBlockedTimeController::class, 'store'])->name('admin.recurring-blocked-times.store');
        Route::patch('/admin/recurring-blocked-times/{series}', [\App\Http\Controllers\Admin\RecurringBlockedTimeController::class, 'update'])->name('admin.recurring-blocked-times.update');
        Route::delete('/admin/recurring-blocked-times/{series}', [\App\Http\Controllers\Admin\RecurringBlockedTimeController::class, 'destroy'])->name('admin.recurring-blocked-times.destroy');
        Route::patch('/admin/recurring-blocked-times/{series}/occurrences/{date}', [\App\Http\Controllers\Admin\RecurringBlockedTimeController::class, 'updateOccurrence'])->name('admin.recurring-blocked-times.occurrence.update');
        Route::delete('/admin/recurring-blocked-times/{series}/occurrences/{date}', [\App\Http\Controllers\Admin\RecurringBlockedTimeController::class, 'destroyOccurrence'])->name('admin.recurring-blocked-times.occurrence.destroy');
    });

    // Recurring appointments — только ПРОФИ (feature gate)
    Route::middleware('feature:recurring_appointments')->group(function () {
        Route::post('/admin/recurring-appointments/preview', [\App\Http\Controllers\Admin\RecurringAppointmentController::class, 'preview'])->name('admin.recurring-appointments.preview');
        Route::post('/admin/recurring-appointments', [\App\Http\Controllers\Admin\RecurringAppointmentController::class, 'store'])->name('admin.recurring-appointments.store');
        Route::post('/admin/recurring-appointments/from-appointment/{appointment}', [\App\Http\Controllers\Admin\RecurringAppointmentController::class, 'fromExisting'])->name('admin.recurring-appointments.from-existing');
        Route::patch('/admin/appointments/{appointment}/recurring/edit-only-this', [\App\Http\Controllers\Admin\RecurringAppointmentController::class, 'editOnlyThis'])->name('admin.recurring-appointments.edit-only-this');
        Route::patch('/admin/appointments/{appointment}/recurring/cancel-only-this', [\App\Http\Controllers\Admin\RecurringAppointmentController::class, 'cancelOnlyThis'])->name('admin.recurring-appointments.cancel-only-this');
        Route::post('/admin/appointments/{appointment}/recurring/preview-split', [\App\Http\Controllers\Admin\RecurringAppointmentController::class, 'previewSplit'])->name('admin.recurring-appointments.preview-split');
        Route::post('/admin/appointments/{appointment}/recurring/edit-this-and-future', [\App\Http\Controllers\Admin\RecurringAppointmentController::class, 'editThisAndFuture'])->name('admin.recurring-appointments.edit-this-and-future');
        Route::post('/admin/appointments/{appointment}/recurring/cancel-this-and-future', [\App\Http\Controllers\Admin\RecurringAppointmentController::class, 'cancelThisAndFuture'])->name('admin.recurring-appointments.cancel-this-and-future');
        Route::post('/admin/appointments/{appointment}/recurring/cancel-whole-series', [\App\Http\Controllers\Admin\RecurringAppointmentController::class, 'cancelWholeSeries'])->name('admin.recurring-appointments.cancel-whole-series');
    });

    Route::get('/admin/billing', [PaymentController::class, 'index'])->name('admin.billing');
    Route::post('/admin/checkout', [PaymentController::class, 'createCheckout'])->name('admin.checkout');
});

Route::get('/max/diag/send-test-button', function (\Illuminate\Http\Request $request) {
    abort_unless($request->query('secret') === config('services.max.secret_token'), 403);
    $chatId = $request->query('chat_id');
    abort_if(empty($chatId), 400, 'chat_id required');
    $ok = app(\App\Services\MaxApiClient::class)->sendCallbackTestButton($chatId);

    return response()->json(['sent' => $ok]);
});

Route::post('/webhooks/payment', [PaymentWebhookController::class, 'handle'])->middleware('throttle:60,1')->name('webhooks.payment');

Route::middleware(['auth'])->prefix('admin-root')->group(function () {
    Route::get('/', [SuperAdminController::class, 'index'])->middleware('platform_permission:dashboard.view')->name('super_admin.dashboard');
    Route::get('/users', [SuperAdminController::class, 'users'])->middleware('platform_permission:users.view')->name('super_admin.users');
    Route::post('/users/{user}/block', [SuperAdminController::class, 'blockUser'])->middleware('platform_permission:users.block')->name('super_admin.block');
    Route::post('/users/{user}/extend', [SuperAdminController::class, 'extendSubscription'])->middleware('platform_permission:subscriptions.extend')->name('super_admin.extend');
    Route::post('/users/{user}/impersonate', [SuperAdminController::class, 'impersonate'])->middleware('platform_permission:impersonation.use')->name('super_admin.impersonate');
    Route::get('/plans', [SuperAdminController::class, 'plans'])->middleware('platform_permission:plans.view')->name('super_admin.plans');
    Route::put('/plans/{plan}', [SuperAdminController::class, 'updatePlan'])->middleware('platform_permission:plans.update')->name('super_admin.update_plan');
    Route::get('/audit', [SuperAdminController::class, 'audit'])->middleware('platform_permission:audit.view')->name('super_admin.audit');

    Route::get('/admins', [PlatformAdminController::class, 'index'])->middleware('platform_permission:platform_admins.manage')->name('super_admin.admins');
    Route::get('/admins/users/search', [PlatformAdminController::class, 'searchUsers'])->middleware('platform_permission:platform_admins.manage')->name('super_admin.admins.users.search');
    Route::post('/admins', [PlatformAdminController::class, 'store'])->middleware('platform_permission:platform_admins.manage')->name('super_admin.admins.store');
    Route::put('/admins/{access}', [PlatformAdminController::class, 'update'])->middleware('platform_permission:platform_admins.manage')->name('super_admin.admins.update');
    Route::patch('/admins/{access}/active', [PlatformAdminController::class, 'toggleActive'])->middleware('platform_permission:platform_admins.manage')->name('super_admin.admins.toggle_active');
});

Route::middleware(['auth', 'can_leave_impersonation'])->post('/admin-root/leave-impersonate', [SuperAdminController::class, 'leaveImpersonate'])->name('super_admin.leave_impersonate');

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
