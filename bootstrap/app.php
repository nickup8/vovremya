<?php

use App\Http\Middleware\EnsureCanLeaveImpersonation;
use App\Http\Middleware\EnsureHasFeature;
use App\Http\Middleware\EnsureHasPlatformPermission;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SuperAdminMiddleware;
use App\Http\Middleware\VerifyMaxInitData;
use App\Http\Middleware\VerifyMiniAppAuth;
use App\Http\Middleware\VerifyVkLaunchParams;
use App\Http\Middleware\XRobotsNoindex;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            require __DIR__.'/../routes/dev.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'super_admin' => SuperAdminMiddleware::class,
            'can_leave_impersonation' => EnsureCanLeaveImpersonation::class,
            'platform_permission' => EnsureHasPlatformPermission::class,
            'max.initdata' => VerifyMaxInitData::class,
            'vk.launch' => VerifyVkLaunchParams::class,
            'miniapp.auth' => VerifyMiniAppAuth::class,
            'feature' => EnsureHasFeature::class,
        ]);

        $middleware->append(XRobotsNoindex::class);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
            'telegraph/*/webhook',
            'max/webhook',
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Подтверждённые JSON-клиенты N12 (Accept: application/json):
        //   POST /book/{master}                 — виджет записи
        //   GET  /admin/free-windows            — панель свободных окон
        //   POST /admin/free-windows/publications
        // Валидация и abort(422) → 422 JSON; auth/403/404/500 — без изменений.
        // Остальные web-маршруты — redirect + session errors, как раньше.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*')
                || (
                    $request->expectsJson()
                    && ($request->is('book/*') || $request->is('admin/free-windows*'))
                    && (
                        $e instanceof ValidationException
                        || ($e instanceof HttpException && $e->getStatusCode() === 422)
                    )
                ),
        );
    })->create();
