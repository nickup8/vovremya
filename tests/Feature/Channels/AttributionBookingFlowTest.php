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
use Illuminate\Support\Facades\Cookie;
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

            public function captureByToken(User $master, TrackingLink $link, Request $request): void {}

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

    // ─── Active tracking link: redirect + attribution cookie ───

    public function test_active_tracking_link_redirects_and_sets_attribution(): void
    {
        [$master] = $this->bookableMaster();
        TrackingLink::factory()->forMaster($master)->create(['token' => 'insta1', 'is_active' => true]);

        $get = $this->get('/r/insta1');
        $get->assertRedirect(route('booking.widget', $master->master_slug));
        $this->assertNotNull($this->attributionCookie($get));
    }

    // ─── Clean redirect: no ?ref= or token in Location ───

    public function test_redirect_location_is_clean(): void
    {
        [$master] = $this->bookableMaster();
        TrackingLink::factory()->forMaster($master)->create(['token' => 'tok123', 'is_active' => true]);

        $get = $this->get('/r/tok123');
        $location = $get->headers->get('Location');
        $this->assertStringNotContainsString('?ref=', $location);
        $this->assertStringNotContainsString('tok123', $location);
        $this->assertStringContainsString('/book/', $location);
    }

    // ─── Invalid token: 404 ───

    public function test_invalid_token_returns_404(): void
    {
        $this->get('/r/nonexistent-token')->assertNotFound();
    }

    // ─── Disabled link: redirect without attribution ───

    public function test_disabled_link_redirects_without_attribution(): void
    {
        [$master] = $this->bookableMaster();
        TrackingLink::factory()->forMaster($master)->inactive()->create(['token' => 'off1']);

        $get = $this->get('/r/off1');
        $get->assertRedirect(route('booking.widget', $master->master_slug));
        $this->assertNull($this->attributionCookie($get));
    }

    // ─── Disabled link does not reset previous attribution ───

    public function test_disabled_link_does_not_reset_previous_attribution(): void
    {
        [$master] = $this->bookableMaster();
        TrackingLink::factory()->forMaster($master)->create(['token' => 'linkA', 'is_active' => true]);
        TrackingLink::factory()->forMaster($master)->inactive()->create(['token' => 'linkB']);

        // Active link A sets attribution cookie.
        $getA = $this->get('/r/linkA');
        $getA->assertRedirect();
        $this->assertNotNull($this->attributionCookie($getA));

        // Flush queued cookies so the next request doesn't carry A's cookie.
        Cookie::flushQueuedCookies();

        // Disabled link B: redirects to widget, does NOT queue a new attribution cookie.
        $getB = $this->get('/r/linkB');
        $getB->assertRedirect(route('booking.widget', $master->master_slug));
        $this->assertNull($this->attributionCookie($getB));
    }

    // ─── Tracked click then booking attaches tracking_link_id ───

    public function test_tracked_click_then_booking_attaches_tracking_link_id(): void
    {
        [$master, $service] = $this->bookableMaster();
        $link = TrackingLink::factory()->forMaster($master)->create(['token' => 'insta1', 'is_active' => true]);

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

    // ─── Booking without attribution → null tracking_link_id ───

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

    // ─── Appointment keeps tracking_link_id after attribution changes ───

    public function test_appointment_keeps_its_tracking_link_after_attribution_changes(): void
    {
        [$master, $service] = $this->bookableMaster();
        $insta = TrackingLink::factory()->forMaster($master)->create(['token' => 'insta1', 'is_active' => true]);

        $this->stubAttribution($insta->id);
        $slot = $this->slot();
        $this->postJson("/book/{$master->master_slug}", [
            'service_id' => $service->id, 'date' => $slot['date'], 'time' => $slot['time'], 'provider' => 'telegram',
        ])->assertOk();

        $appt = Appointment::where('master_id', $master->id)->first();
        $this->assertSame($insta->id, $appt->tracking_link_id);

        $vk = TrackingLink::factory()->forMaster($master)->create(['token' => 'vk1', 'is_active' => true]);
        $this->stubAttribution($vk->id);
        $this->assertSame($insta->id, $appt->fresh()->tracking_link_id);
    }

    // ─── Old format /book/{slug}?ref=... no longer sets attribution ───

    public function test_old_ref_format_does_not_set_attribution(): void
    {
        [$master, $service] = $this->bookableMaster();
        TrackingLink::factory()->forMaster($master)->create(['token' => 'insta1', 'is_active' => true]);

        $get = $this->get("/book/{$master->master_slug}?ref=insta1");
        $get->assertOk();
        $this->assertNull($this->attributionCookie($get));
    }

    // ─── Downgrade PRO→START still works for /r/{token} ───

    public function test_downgrade_to_start_still_collects_attribution(): void
    {
        [$master, $service] = $this->bookableMaster();
        Subscription::create([
            'workspace_id' => $master->workspace_id,
            'tariff_plan_id' => $this->proPlan()->id,
            'period_months' => 1, 'amount_paid' => 490,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subDay(), 'expires_at' => now()->addMonth(),
        ]);
        $link = TrackingLink::factory()->forMaster($master)->create(['token' => 'insta1', 'is_active' => true]);

        $this->downgradeToStart($master->fresh());

        // Public /r/{token} works regardless of tariff.
        $get = $this->get('/r/insta1');
        $get->assertRedirect(route('booking.widget', $master->master_slug));
        $this->assertNotNull($this->attributionCookie($get));

        // And booking on START still fixes the source.
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

    // ─── Disabled link + booking → null tracking_link_id ───

    public function test_disabled_token_does_not_break_widget_and_sets_no_attribution(): void
    {
        [$master, $service] = $this->bookableMaster();
        TrackingLink::factory()->forMaster($master)->inactive()->create(['token' => 'off1']);

        $get = $this->get('/r/off1');
        $get->assertRedirect(route('booking.widget', $master->master_slug));
        $this->assertNull($this->attributionCookie($get));

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
}
