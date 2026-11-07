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
        // JSON-клиенты (виджет, drawer, axios) получают 422 c текстом ошибки,
        // а не HTML-редирект. Auth/403/404 без validate() остаются как раньше.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*')
                || ($request->expectsJson() && (
                    $e instanceof ValidationException
                    || ($e instanceof HttpException && $e->getStatusCode() === 422)
                )),
        );
    })->create();
