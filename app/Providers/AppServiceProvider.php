<?php

namespace App\Providers;

use App\Events\AppointmentCreated;
use App\Events\AppointmentRescheduled;
use App\Events\AppointmentStatusChanged;
use App\Listeners\FlushAvailabilityCache;
use App\Models\BlockedTime;
use App\Models\MasterService;
use App\Models\RecurringBlockedTimeException;
use App\Models\RecurringBlockedTimeSeries;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WorkingHour;
use App\Observers\BlockedTimeObserver;
use App\Observers\MasterServiceObserver;
use App\Observers\RecurringBlockedTimeExceptionObserver;
use App\Observers\RecurringBlockedTimeSeriesObserver;
use App\Observers\SubscriptionObserver;
use App\Observers\UserObserver;
use App\Observers\WorkingHourObserver;
use App\Services\Backups\BackupProcessRunner;
use App\Services\Backups\LocalBackupProcessRunner;
use App\Services\Payment\PaymentGatewayInterface;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\TBankPaymentGateway;
use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Psr\Http\Message\RequestInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PaymentGatewayManager::class, function ($app) {
            return new PaymentGatewayManager($app, config('billing.gateways', []));
        });

        $this->app->bind(PaymentGatewayInterface::class, function ($app) {
            return $app->make(PaymentGatewayManager::class)->getDefault();
        });

        $this->app->bind(
            BackupProcessRunner::class,
            LocalBackupProcessRunner::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Http::globalOptions([
            'connect_timeout' => 3,
            'timeout' => 20,
        ]);

        Http::globalMiddleware(Middleware::retry(
            function (int $retries, RequestInterface $request, $response = null, ?\Throwable $exception = null): bool {
                // T-Bank Init/Charge of the configured gateway (same origin
                // and path as the gateway builds them, incl. any
                // TBANK_BASE_URL prefix) are single-attempt: a
                // ConnectException can arrive AFTER the request was sent
                // (response timeout), so a transport retry here could hit
                // the bank with a second Init/Charge. The undefined outcome
                // stays with CheckOrder recovery, the charge dispatch marker
                // and reconciliation — never with an automatic re-send.
                // Every other request keeps the retry budget below.
                $singleAttempt = $exception instanceof ConnectException
                    && TBankPaymentGateway::isSingleAttemptUrl((string) $request->getUri());

                // Inertia SSR — тоже single-attempt: при недоступном узле
                // CSR-фолбэк не должен ждать ретраев (~9с пауз на GET /).
                // Точное сопоставление origin+path (схема, хост, эффективный
                // порт, путь): пустой или некорректный inertia.ssr.url не
                // матчится и не может отключить ретраи.
                $inertiaSsr = $exception instanceof ConnectException
                    && self::isInertiaSsrUrl((string) $request->getUri());

                if ($singleAttempt || $inertiaSsr) {
                    return false;
                }

                return $retries < 5 && $exception instanceof ConnectException;
            },
            function (int $retries): int {
                return (int) (300 * (2 ** ($retries - 1)));
            }
        ));

        // SSR включается только для гостевого GET / (welcome-v2); остальные
        // маршруты остаются в CSR. disableSsr имеет приоритет над
        // config('inertia.ssr.enabled'), поэтому общий выключатель
        // INERTIA_SSR_ENABLED проверяется здесь же — в обход него SSR
        // не включается.
        Inertia::disableSsr(function (Request $request): bool {
            if (! config('inertia.ssr.enabled', false)) {
                return true;
            }

            return $request->method() !== 'GET'
                || $request->path() !== '/'
                || auth()->check();
        });

        User::observe(UserObserver::class);
        WorkingHour::observe(WorkingHourObserver::class);
        BlockedTime::observe(BlockedTimeObserver::class);
        MasterService::observe(MasterServiceObserver::class);
        RecurringBlockedTimeSeries::observe(RecurringBlockedTimeSeriesObserver::class);
        RecurringBlockedTimeException::observe(RecurringBlockedTimeExceptionObserver::class);
        Subscription::observe(SubscriptionObserver::class);

        // Flush availability cache on any appointment change
        $listener = FlushAvailabilityCache::class;
        Event::listen(AppointmentCreated::class, $listener);
        Event::listen(AppointmentStatusChanged::class, $listener);
        Event::listen(AppointmentRescheduled::class, $listener);

        // VK-подтверждение брони шлёт только outbox
        // (AppointmentNotificationEventType::BookingConfirmed): listener'
        // SendVkBookingConfirmation удалён, чтобы исключить параллельную
        // отправку и дубли.

        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * True when the URL is an endpoint of the SSR server this app dispatches
     * to: exact scheme, host, effective port and path — the same origin with
     * another path, or a look-alike host/port/scheme, keeps the normal retry
     * behaviour. An empty or unparsable configured URL matches nothing, so
     * it can never switch retries off.
     */
    private static function isInertiaSsrUrl(string $url): bool
    {
        $target = self::originAndPath($url);

        if ($target === null) {
            return false;
        }

        foreach (self::inertiaSsrUrls() as $candidate) {
            if (self::originAndPath($candidate) === $target) {
                return true;
            }
        }

        return false;
    }

    /**
     * The exact SSR requests HttpGateway and the health check send, built the
     * way the vendor builds them: POST {inertia.ssr.url}/render and
     * GET /health (with HttpGateway's default when the config key is
     * missing), plus the Vite-hot dev endpoint {hot}/__inertia_ssr — that
     * dispatch goes through this same retry middleware.
     *
     * @return list<string>
     */
    private static function inertiaSsrUrls(): array
    {
        $base = rtrim((string) config('inertia.ssr.url', 'http://127.0.0.1:13714'), '/');
        $urls = [$base.'/render', $base.'/health'];

        if (Vite::isRunningHot()) {
            // Same rtrim as HttpGateway::getHotUrl() (whitespace only).
            $urls[] = rtrim((string) file_get_contents(Vite::hotFile())).'/__inertia_ssr';
        }

        return $urls;
    }

    private static function originAndPath(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $port = isset($parts['port'])
            ? (int) $parts['port']
            : self::defaultPortForScheme($scheme);

        return $scheme
            .'://'.strtolower((string) $parts['host'])
            .($port !== null ? ':'.$port : '')
            .($parts['path'] ?? '');
    }

    private static function defaultPortForScheme(string $scheme): ?int
    {
        return match ($scheme) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }
}
