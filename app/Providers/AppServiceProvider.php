<?php

namespace App\Providers;

use App\Events\AppointmentCreated;
use App\Events\AppointmentRescheduled;
use App\Events\AppointmentStatusChanged;
use App\Listeners\FlushAvailabilityCache;
use App\Listeners\SendVkBookingConfirmation;
use App\Models\BlockedTime;
use App\Models\MasterService;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WorkingHour;
use App\Observers\BlockedTimeObserver;
use App\Observers\MasterServiceObserver;
use App\Observers\SubscriptionObserver;
use App\Observers\UserObserver;
use App\Observers\WorkingHourObserver;
use App\Services\Payment\MockPaymentGateway;
use App\Services\Payment\PaymentGatewayInterface;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\TBankPaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use GuzzleHttp\Middleware;
use GuzzleHttp\Exception\ConnectException;
use Psr\Http\Message\RequestInterface;
use Illuminate\Validation\Rules\Password;

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

                if ($singleAttempt) {
                    return false;
                }

                return $retries < 5 && $exception instanceof ConnectException;
            },
            function (int $retries): int {
                return (int) (300 * (2 ** ($retries - 1)));
            }
        ));

        User::observe(UserObserver::class);
        WorkingHour::observe(WorkingHourObserver::class);
        BlockedTime::observe(BlockedTimeObserver::class);
        MasterService::observe(MasterServiceObserver::class);
        \App\Models\RecurringBlockedTimeSeries::observe(\App\Observers\RecurringBlockedTimeSeriesObserver::class);
        \App\Models\RecurringBlockedTimeException::observe(\App\Observers\RecurringBlockedTimeExceptionObserver::class);
        Subscription::observe(SubscriptionObserver::class);

        // Flush availability cache on any appointment change
        $listener = FlushAvailabilityCache::class;
        Event::listen(AppointmentCreated::class, $listener);
        Event::listen(AppointmentStatusChanged::class, $listener);
        Event::listen(AppointmentRescheduled::class, $listener);

        // VK booking confirmation to client
        Event::listen(AppointmentCreated::class, SendVkBookingConfirmation::class);

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
}
