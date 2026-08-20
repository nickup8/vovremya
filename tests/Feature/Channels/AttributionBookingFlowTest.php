<?php

namespace Tests\Feature\Channels;

use App\Enums\SubscriptionStatus;
use App\Models\Appointment;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\Subscription;
use App\Models\TrackingLink;
use App\Models\User;
use App\Models\WorkingHour;
use App\Models\Workspace;
use App\Services\Booking\AttributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AttributionBookingFlowTest extends TestCase
{
    use MakesTariffMasters, RefreshDatabase;

    /**
     * Подменяет AttributionService стабом, резолвящим заданный link id.
     * Изолирует контроллерную склейку resolve→createAppointment от cookie-шифрования
     * (сам сервис и capture покрыты отдельными тестами).
     */
    private function stubAttribution(?string $linkId): void
    {
        $this->app->instance(AttributionService::class, new class($linkId) extends AttributionService
        {
            public function __construct(private ?string $stubLinkId) {}

            public function captureFromRequest(User $master, Request $request): void {}

            public function resolveLinkId(User $master, Request $request): ?string
            {
                return $this->stubLinkId;
            }
        });
    }

    private function bookableMaster(): array
    {
        $master = User::factory()->master()->create([
            'master_slug' => 'flow-master-'.uniqid(),
            'is_service_provider' => true,
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);
        $ws = Workspace::create(['name' => 'WS '.uniqid(), 'owner_id' => $master->id]);
        $master->update(['workspace_id' => $ws->id]);

        for ($day = 0; $day <= 6; $day++) {
            WorkingHour::updateOrCreate(
                ['user_id' => $master->id, 'day_of_week' => $day],
                ['start_time' => '09:00', 'end_time' => '18:00', 'is_working' => true],
            );
        }

        $catalog = ServiceCatalog::create(['workspace_id' => $ws->id, 'title' => 'Стрижка', 'base_price' => 1000, 'base_duration' => 60, 'is_active' => true]);
        $service = MasterService::create(['master_id' => $master->id, 'catalog_id' => $catalog->id, 'is_active' => true]);

        return [$master->fresh(), $service];
    }

    private function slot(): array
    {
        $dt = Carbon::tomorrow('Europe/Moscow')->setTime(11, 0);

        return ['date' => $dt->format('Y-m-d'), 'time' => $dt->format('H:i')];
    }

    private function attributionCookie($getResponse): ?string
    {
        foreach ($getResponse->headers->getCookies() as $c) {
            if ($c->getName() === 'booking_attribution') {
                return $c->getValue();
            }
        }

        return null;
    }

    public function test_get_with_ref_sets_attribution_cookie(): void
    {
        [$master] = $this->bookableMaster();
        TrackingLink::factory()->forMaster($master)->create(['token' => 'insta1', 'is_active' => true]);

        // GET виджета с ?ref — контроллер снимает attribution в cookie (capture wiring).
        $get = $this->get("/book/{$master->master_slug}?ref=insta1");
        $get->assertOk();
        $this->assertNotNull($this->attributionCookie($get));
    }

    public function test_tracked_click_then_booking_attaches_tracking_link_id(): void
    {
        [$master, $service] = $this->bookableMaster();
        $link = TrackingLink::factory()->forMaster($master)->create(['token' => 'insta1', 'is_active' => true]);

        // Контроллер резолвит источник и создаёт запись (resolve + createAppointment(trackingLinkId:) wiring).
        $this->stubAttribution($link->id);

        $slot = $this->slot();
        $this->postJson("/book/{$master->master_slug}", [
            'service_id' => $service->id,
            'date' => $slot['date'],
            'time' => $slot['time'],
            'provider' => 'telegram',
        ])->assertOk();

        $appt = Appointment::where('master_id', $master->id)->first();
        $this->assertNotNull($appt);
        $this->assertSame($link->id, $appt->tracking_link_id);
    }

    public function test_appointment_keeps_its_tracking_link_after_attribution_changes(): void
    {
        [$master, $service] = $this->bookableMaster();
        $insta = TrackingLink::factory()->forMaster($master)->create(['token' => 'insta1', 'is_active' => true]);

        // Первая запись — источник Instagram.
        $this->stubAttribution($insta->id);
        $slot = $this->slot();
        $this->postJson("/book/{$master->master_slug}", [
            'service_id' => $service->id, 'date' => $slot['date'], 'time' => $slot['time'], 'provider' => 'telegram',
        ])->assertOk();

        $appt = Appointment::where('master_id', $master->id)->first();
        $this->assertSame($insta->id, $appt->tracking_link_id);

        // Смена текущей attribution (последующий резолв другого источника) не меняет уже созданную запись.
        $vk = TrackingLink::factory()->forMaster($master)->create(['token' => 'vk1', 'is_active' => true]);
        $this->stubAttribution($vk->id);
        $this->assertSame($insta->id, $appt->fresh()->tracking_link_id);
    }

    public function test_booking_without_ref_has_null_tracking_link_id(): void
    {
        [$master, $service] = $this->bookableMaster();
        $this->get("/book/{$master->master_slug}")->assertOk();

        $slot = $this->slot();
        $this->postJson("/book/{$master->master_slug}", [
            'service_id' => $service->id,
            'date' => $slot['date'],
            'time' => $slot['time'],
            'provider' => 'telegram',
        ])->assertOk();

        $appt = Appointment::where('master_id', $master->id)->first();
        $this->assertNull($appt->tracking_link_id);
    }

    public function test_disabled_token_does_not_break_widget_and_sets_no_attribution(): void
    {
        [$master, $service] = $this->bookableMaster();
        TrackingLink::factory()->forMaster($master)->inactive()->create(['token' => 'off1']);

        // Widget работает даже с disabled ref.
        $get = $this->get("/book/{$master->master_slug}?ref=off1");
        $get->assertOk();
        $this->assertNull($this->attributionCookie($get)); // attribution не установлена

        $slot = $this->slot();
        $this->postJson("/book/{$master->master_slug}", [
            'service_id' => $service->id,
            'date' => $slot['date'],
            'time' => $slot['time'],
            'provider' => 'telegram',
        ])->assertOk();

        $appt = Appointment::where('master_id', $master->id)->first();
        $this->assertNull($appt->tracking_link_id);
    }

    public function test_downgrade_to_start_still_collects_attribution(): void
    {
        [$master, $service] = $this->bookableMaster();
        // Дать ПРОФИ, создать ссылку, затем downgrade.
        Subscription::create([
            'workspace_id' => $master->workspace_id,
            'tariff_plan_id' => $this->proPlan()->id,
            'period_months' => 1, 'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(), 'expires_at' => now()->addMonth(),
        ]);
        $link = TrackingLink::factory()->forMaster($master)->create(['token' => 'insta1', 'is_active' => true]);

        $this->downgradeToStart($master->fresh());

        // Публичный сбор attribution НЕ зависит от тарифа: GET у START-мастера всё равно ставит cookie.
        $get = $this->get("/book/{$master->master_slug}?ref=insta1");
        $get->assertOk();
        $this->assertNotNull($this->attributionCookie($get));

        // И booking на START всё равно фиксирует источник (resolve не гейтится тарифом).
        $this->stubAttribution($link->id);
        $slot = $this->slot();
        $this->postJson("/book/{$master->master_slug}", [
            'service_id' => $service->id,
            'date' => $slot['date'],
            'time' => $slot['time'],
            'provider' => 'telegram',
        ])->assertOk();

        $appt = Appointment::where('master_id', $master->id)->first();
        $this->assertSame($link->id, $appt->tracking_link_id);
    }
}
