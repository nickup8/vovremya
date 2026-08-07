<?php

namespace App\Providers;

use App\Events\AppointmentCreated;
use App\Events\AppointmentRescheduled;
use App\Events\AppointmentStatusChanged;
use App\Listeners\FlushAvailabilityCache;
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
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentGatewayInterface::class, fn () => new MockPaymentGateway);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        User::observe(UserObserver::class);
        WorkingHour::observe(WorkingHourObserver::class);
        BlockedTime::observe(BlockedTimeObserver::class);
        MasterService::observe(MasterServiceObserver::class);
        Subscription::observe(SubscriptionObserver::class);

        // Flush availability cache on any appointment change
        $listener = FlushAvailabilityCache::class;
        Event::listen(AppointmentCreated::class, $listener);
        Event::listen(AppointmentStatusChanged::class, $listener);
        Event::listen(AppointmentRescheduled::class, $listener);

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
