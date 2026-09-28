<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BlockedTime;
use App\Models\Client;
use App\Models\FreeWindowPublication;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkingHour;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FreeWindowPublicationTest extends TestCase
{
    use RefreshDatabase;

    private string $testAppUrl = 'https://irsi.test';

    private User $master;
    private Workspace $workspace;
    private ServiceCatalog $catalog;
    private MasterService $masterService;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => $this->testAppUrl]);
        $this->app['url']->forceRootUrl($this->testAppUrl);
        $this->app['url']->forceScheme('https');

        $proPlan = TariffPlan::create([
            'code' => 'pro',
            'name' => 'Профи',
            'price_monthly' => 490,
            'max_appointments_per_month' => null,
            'max_masters' => 1,
            'features' => ['unlimited_appointments', 'client_management', 'channel_analytics', 'slot_autofill', 'recurring_appointments', 'free_windows'],
            'is_active' => true,
        ]);

        $this->master = User::factory()->master()->create([
            'is_service_provider' => true,
            'settings' => ['timezone' => 'Europe/Moscow'],
        ]);

        $this->workspace = Workspace::create([
            'name' => 'FW Pub Test Studio',
            'owner_id' => $this->master->id,
        ]);
        $this->master->update(['workspace_id' => $this->workspace->id]);

        Subscription::create([
            'workspace_id' => $this->workspace->id,
            'tariff_plan_id' => $proPlan->id,
            'status' => 'active',
            'expires_at' => now()->addYear(),
        ]);

        // Working hours: Mon-Fri 09:00-18:00, no breaks
        for ($day = 1; $day <= 5; $day++) {
            WorkingHour::updateOrCreate(
                ['user_id' => $this->master->id, 'day_of_week' => $day],
                ['is_working' => true, 'start_time' => '09:00', 'end_time' => '18:00', 'break_start_time' => null, 'break_end_time' => null]
            );
        }
        for ($day = 0; $day <= 6; $day += 6) {
            WorkingHour::updateOrCreate(
                ['user_id' => $this->master->id, 'day_of_week' => $day],
                ['is_working' => false, 'start_time' => '09:00', 'end_time' => '18:00']
            );
        }

        $this->catalog = ServiceCatalog::create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Маникюр',
            'base_duration' => 60,
            'base_price' => 2000,
            'is_active' => true,
        ]);

        $this->masterService = MasterService::create([
            'master_id' => $this->master->id,
            'catalog_id' => $this->catalog->id,
            'effective_duration' => 60,
            'is_active' => true,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // §27: Publication creation
    // ═══════════════════════════════════════════════════════
    public function test_create_service_publication(): void
    {
        $this->actingAs($this->master);

        $date = $this->getNextWeekday();

        $response = $this->postJson('/admin/free-windows/publications', [
            'mode' => 'service',
            'service_id' => $this->masterService->id,
            'date_from' => $date->format('Y-m-d'),
            'date_to' => $date->format('Y-m-d'),
            'days' => [
                ['date' => $date->format('Y-m-d'), 'starts' => ['09:00', '09:30', '10:00']],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['token', 'url', 'mode', 'expires_at']);
        $response->assertJson(['mode' => 'service']);
        $this->assertStringContainsString('fw=', $response->json('url'));
        $this->assertStringContainsString('service_id=', $response->json('url'));
    }

    public function test_create_all_publication(): void
    {
        $this->actingAs($this->master);

        $date = $this->getNextWeekday();

        $response = $this->postJson('/admin/free-windows/publications', [
            'mode' => 'all',
            'date_from' => $date->format('Y-m-d'),
            'date_to' => $date->format('Y-m-d'),
            'days' => [
                ['date' => $date->format('Y-m-d'), 'ranges' => [['start' => '09:00', 'end' => '13:00']]],
            ],
        ]);

        $response->assertOk();
        $response->assertJson(['mode' => 'all']);
        $this->assertStringContainsString('fw=', $response->json('url'));
        $this->assertStringNotContainsString('service_id=', $response->json('url'));
    }

    public function test_empty_days_rejected(): void
    {
        $this->actingAs($this->master);

        $date = $this->getNextWeekday();

        $response = $this->postJson('/admin/free-windows/publications', [
            'mode' => 'service',
            'service_id' => $this->masterService->id,
            'date_from' => $date->format('Y-m-d'),
            'date_to' => $date->format('Y-m-d'),
            'days' => [],
        ]);

        $this->assertContains($response->status(), [422, 302]);
    }

    public function test_same_content_reuses_token(): void
    {
        $this->actingAs($this->master);

        $date = $this->getNextWeekday();
        $payload = [
            'mode' => 'service',
            'service_id' => $this->masterService->id,
            'date_from' => $date->format('Y-m-d'),
            'date_to' => $date->format('Y-m-d'),
            'days' => [
                ['date' => $date->format('Y-m-d'), 'starts' => ['09:00', '09:30']],
            ],
        ];

        $r1 = $this->postJson('/admin/free-windows/publications', $payload)->json();
        $r2 = $this->postJson('/admin/free-windows/publications', $payload)->json();

        $this->assertEquals($r1['token'], $r2['token']);
        $this->assertEquals($r1['url'], $r2['url']);
    }

    public function test_changed_content_creates_new_token(): void
    {
        $this->actingAs($this->master);

        $date = $this->getNextWeekday();

        $r1 = $this->postJson('/admin/free-windows/publications', [
            'mode' => 'service',
            'service_id' => $this->masterService->id,
            'date_from' => $date->format('Y-m-d'),
            'date_to' => $date->format('Y-m-d'),
            'days' => [
                ['date' => $date->format('Y-m-d'), 'starts' => ['09:00', '09:30']],
            ],
        ])->json();

        $r2 = $this->postJson('/admin/free-windows/publications', [
            'mode' => 'service',
            'service_id' => $this->masterService->id,
            'date_from' => $date->format('Y-m-d'),
            'date_to' => $date->format('Y-m-d'),
            'days' => [
                ['date' => $date->format('Y-m-d'), 'starts' => ['09:00']],
            ],
        ])->json();

        $this->assertNotEquals($r1['token'], $r2['token']);
    }

    public function test_absolute_url_in_response(): void
    {
        $this->actingAs($this->master);

        $date = $this->getNextWeekday();

        $response = $this->postJson('/admin/free-windows/publications', [
            'mode' => 'all',
            'date_from' => $date->format('Y-m-d'),
            'date_to' => $date->format('Y-m-d'),
            'days' => [
                ['date' => $date->format('Y-m-d'), 'ranges' => [['start' => '09:00', 'end' => '13:00']]],
            ],
        ]);

        $url = $response->json('url');
        $this->assertStringStartsWith('https://', $url);
        $this->assertStringStartsWith($this->testAppUrl, $url);
    }

    public function test_14_day_limit_enforced(): void
    {
        $this->actingAs($this->master);

        $date = $this->getNextWeekday();

        $response = $this->postJson('/admin/free-windows/publications', [
            'mode' => 'all',
            'date_from' => $date->format('Y-m-d'),
            'date_to' => $date->copy()->addDays(20)->format('Y-m-d'),
            'days' => [
                ['date' => $date->format('Y-m-d'), 'ranges' => [['start' => '09:00', 'end' => '13:00']]],
            ],
        ]);

        $this->assertContains($response->status(), [422, 302]);
    }

    public function test_invalid_service_ownership_rejected(): void
    {
        $this->actingAs($this->master);

        $otherMaster = User::factory()->master()->create([
            'is_service_provider' => true,
            'settings' => ['timezone' => 'Europe/Moscow'],
        ]);
        $otherWorkspace = Workspace::create(['name' => 'Other', 'owner_id' => $otherMaster->id]);
        $otherMaster->update(['workspace_id' => $otherWorkspace->id]);
        $otherCatalog = ServiceCatalog::create([
            'workspace_id' => $otherWorkspace->id, 'title' => 'Other', 'base_duration' => 60, 'base_price' => 1000, 'is_active' => true,
        ]);
        $otherService = MasterService::create([
            'master_id' => $otherMaster->id, 'catalog_id' => $otherCatalog->id, 'effective_duration' => 60, 'is_active' => true,
        ]);

        $date = $this->getNextWeekday();

        $response = $this->postJson('/admin/free-windows/publications', [
            'mode' => 'service',
            'service_id' => $otherService->id,
            'date_from' => $date->format('Y-m-d'),
            'date_to' => $date->format('Y-m-d'),
            'days' => [
                ['date' => $date->format('Y-m-d'), 'starts' => ['09:00']],
            ],
        ]);

        $this->assertContains($response->status(), [422, 403, 302]);
    }

    // ═══════════════════════════════════════════════════════
    // §28: Service mode public — fw in show/availableDates/store
    // ═══════════════════════════════════════════════════════
    public function test_service_mode_show_with_fw(): void
    {
        $date = $this->getNextWeekday();

        $pub = $this->createPublication('service', $date, [
            ['date' => $date->format('Y-m-d'), 'starts' => ['09:00', '10:00']],
        ]);

        $response = $this->get("/book/{$this->master->master_slug}?service_id={$this->masterService->id}&fw={$pub->token}");
        $response->assertOk();

        $props = $this->extractInertiaProps($response);
        $this->assertTrue($props['publicationContext']['active']);
        $this->assertFalse($props['publicationContext']['expired']);
    }

    public function test_service_mode_hidden_day_absent_from_dates(): void
    {
        $date = $this->getNextWeekday();

        $pub = $this->createPublication('service', $date, [
            ['date' => $date->format('Y-m-d'), 'starts' => ['09:00']],
        ]);

        // available-dates is a GET route
        $response = $this->getJson("/book/{$this->master->master_slug}/available-dates?service_id={$this->masterService->id}&year={$date->year}&month={$date->month}&fw={$pub->token}");

        $response->assertOk();
        $dates = $response->json('dates');
        $this->assertContains($date->format('Y-m-d'), $dates);
    }

    public function test_service_mode_hidden_start_absent_from_slots(): void
    {
        $date = $this->getNextWeekday();

        $pub = $this->createPublication('service', $date, [
            ['date' => $date->format('Y-m-d'), 'starts' => ['09:00']],
        ]);

        $response = $this->get("/book/{$this->master->master_slug}?service_id={$this->masterService->id}&date={$date->format('Y-m-d')}&fw={$pub->token}");
        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('booking/widget')
            ->reloadOnly('availableSlots', function (Assert $reload) use ($date) {
                $slots = $reload->toArray()['props']['availableSlots'];
                $this->assertContains('09:00', $slots);
                $this->assertNotContains('09:30', $slots);
            })
        );
    }

    public function test_service_mode_visible_start_present(): void
    {
        $date = $this->getNextWeekday();

        $pub = $this->createPublication('service', $date, [
            ['date' => $date->format('Y-m-d'), 'starts' => ['09:00', '10:00']],
        ]);

        $response = $this->get("/book/{$this->master->master_slug}?service_id={$this->masterService->id}&date={$date->format('Y-m-d')}&fw={$pub->token}");
        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('booking/widget')
            ->reloadOnly('availableSlots', function (Assert $reload) {
                $slots = $reload->toArray()['props']['availableSlots'];
                $this->assertContains('09:00', $slots);
                $this->assertContains('10:00', $slots);
            })
        );
    }

    public function test_normal_book_unaffected_without_fw(): void
    {
        $date = $this->getNextWeekday();

        $response = $this->get("/book/{$this->master->master_slug}?service_id={$this->masterService->id}&date={$date->format('Y-m-d')}");
        $response->assertOk();

        $props = $this->extractInertiaProps($response);
        $this->assertNull($props['publicationContext']);

        $response->assertInertia(fn (Assert $page) => $page
            ->component('booking/widget')
            ->reloadOnly('availableSlots', function (Assert $reload) {
                $slots = $reload->toArray()['props']['availableSlots'];
                $this->assertNotEmpty($slots);
            })
        );
    }

    public function test_service_mismatch_rejected_on_store(): void
    {
        $date = $this->getNextWeekday();

        $catalog2 = ServiceCatalog::create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Педикюр',
            'base_duration' => 45,
            'base_price' => 1500,
            'is_active' => true,
        ]);
        $service2 = MasterService::create([
            'master_id' => $this->master->id,
            'catalog_id' => $catalog2->id,
            'effective_duration' => 45,
            'is_active' => true,
        ]);

        $pub = $this->createPublication('service', $date, [
            ['date' => $date->format('Y-m-d'), 'starts' => ['09:00']],
        ]);

        $response = $this->postJson("/book/{$this->master->master_slug}", [
            'service_id' => $service2->id,
            'date' => $date->format('Y-m-d'),
            'time' => '09:00',
            'provider' => 'vk',
            'fw' => $pub->token,
        ]);

        $response->assertStatus(422);
    }

    public function test_date_outside_snapshot_rejected(): void
    {
        $date = $this->getNextWeekday();

        $pub = $this->createPublication('service', $date, [
            ['date' => $date->format('Y-m-d'), 'starts' => ['09:00']],
        ]);

        $otherDate = $date->copy()->addDays(5);
        while ($otherDate->isWeekend()) $otherDate->addDay();

        $response = $this->postJson("/book/{$this->master->master_slug}", [
            'service_id' => $this->masterService->id,
            'date' => $otherDate->format('Y-m-d'),
            'time' => '09:00',
            'provider' => 'vk',
            'fw' => $pub->token,
        ]);

        $response->assertStatus(422);
    }

    public function test_time_outside_snapshot_rejected(): void
    {
        $date = $this->getNextWeekday();

        $pub = $this->createPublication('service', $date, [
            ['date' => $date->format('Y-m-d'), 'starts' => ['09:00']],
        ]);

        $response = $this->postJson("/book/{$this->master->master_slug}", [
            'service_id' => $this->masterService->id,
            'date' => $date->format('Y-m-d'),
            'time' => '10:00',
            'provider' => 'vk',
            'fw' => $pub->token,
        ]);

        $response->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════
    // §29: All mode public — range containment
    // ═══════════════════════════════════════════════════════
    public function test_all_mode_range_containment_slots(): void
    {
        $date = $this->getNextWeekday();

        $pub = $this->createPublication('all', $date, [
            ['date' => $date->format('Y-m-d'), 'ranges' => [['start' => '10:00', 'end' => '13:00']]],
        ], serviceId: $this->masterService->id);

        $response = $this->get("/book/{$this->master->master_slug}?service_id={$this->masterService->id}&date={$date->format('Y-m-d')}&fw={$pub->token}");
        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('booking/widget')
            ->reloadOnly('availableSlots', function (Assert $reload) {
                $slots = $reload->toArray()['props']['availableSlots'];
                $this->assertContains('10:00', $slots);
                $this->assertContains('12:00', $slots);
                $this->assertNotContains('12:30', $slots);
            })
        );
    }

    public function test_all_mode_date_not_selectable_when_zero_fitting_slots(): void
    {
        $date = $this->getNextWeekday();

        $pub = $this->createPublication('all', $date, [
            ['date' => $date->format('Y-m-d'), 'ranges' => [['start' => '09:00', 'end' => '09:30']]],
        ], serviceId: $this->masterService->id);

        $response = $this->get("/book/{$this->master->master_slug}?service_id={$this->masterService->id}&date={$date->format('Y-m-d')}&fw={$pub->token}");
        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('booking/widget')
            ->reloadOnly('availableSlots', function (Assert $reload) {
                $slots = $reload->toArray()['props']['availableSlots'];
                $this->assertEmpty($slots);
            })
        );
    }

    // ═══════════════════════════════════════════════════════
    // §30: Stale availability
    // ═══════════════════════════════════════════════════════
    public function test_slot_becomes_booked_disappears(): void
    {
        $date = $this->getNextWeekday();

        $pub = $this->createPublication('service', $date, [
            ['date' => $date->format('Y-m-d'), 'starts' => ['09:00', '10:00']],
        ]);

        // Verify slots are available
        $this->get("/book/{$this->master->master_slug}?service_id={$this->masterService->id}&date={$date->format('Y-m-d')}&fw={$pub->token}")
            ->assertInertia(fn (Assert $page) => $page
                ->reloadOnly('availableSlots', function (Assert $reload) {
                    $this->assertContains('09:00', $reload->toArray()['props']['availableSlots']);
                })
            );

        // Book 09:00-10:00
        $client = Client::factory()->for($this->master)->create();
        Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client)
            ->withMasterService($this->masterService)
            ->booked()
            ->create([
                'start_time' => $date->copy()->setTime(9, 0)->timezone('UTC'),
                'duration' => 60,
            ]);

        // Re-check — 09:00 should be gone
        $this->get("/book/{$this->master->master_slug}?service_id={$this->masterService->id}&date={$date->format('Y-m-d')}&fw={$pub->token}")
            ->assertInertia(fn (Assert $page) => $page
                ->reloadOnly('availableSlots', function (Assert $reload) {
                    $slots = $reload->toArray()['props']['availableSlots'];
                    $this->assertNotContains('09:00', $slots);
                    $this->assertContains('10:00', $slots);
                })
            );
    }

    public function test_slot_becomes_blocked_disappears(): void
    {
        $date = $this->getNextWeekday();

        $pub = $this->createPublication('service', $date, [
            ['date' => $date->format('Y-m-d'), 'starts' => ['09:00', '10:00']],
        ]);

        BlockedTime::create([
            'user_id' => $this->master->id,
            'start_datetime' => $date->copy()->setTime(9, 0)->timezone('UTC'),
            'end_datetime' => $date->copy()->setTime(10, 0)->timezone('UTC'),
        ]);

        $this->get("/book/{$this->master->master_slug}?service_id={$this->masterService->id}&date={$date->format('Y-m-d')}&fw={$pub->token}")
            ->assertInertia(fn (Assert $page) => $page
                ->reloadOnly('availableSlots', function (Assert $reload) {
                    $slots = $reload->toArray()['props']['availableSlots'];
                    $this->assertNotContains('09:00', $slots);
                })
            );
    }

    public function test_store_after_slot_becomes_booked_returns_422(): void
    {
        $date = $this->getNextWeekday();

        $pub = $this->createPublication('service', $date, [
            ['date' => $date->format('Y-m-d'), 'starts' => ['09:00']],
        ]);

        $client = Client::factory()->for($this->master)->create();
        Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client)
            ->withMasterService($this->masterService)
            ->booked()
            ->create([
                'start_time' => $date->copy()->setTime(9, 0)->timezone('UTC'),
                'duration' => 60,
            ]);

        $response = $this->postJson("/book/{$this->master->master_slug}", [
            'service_id' => $this->masterService->id,
            'date' => $date->format('Y-m-d'),
            'time' => '09:00',
            'provider' => 'vk',
            'fw' => $pub->token,
        ]);

        $response->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════
    // §31: Expiration
    // ═══════════════════════════════════════════════════════
    public function test_expired_show_returns_expired_context(): void
    {
        $date = $this->getNextWeekday();

        $pub = $this->createPublication('service', $date, [
            ['date' => $date->format('Y-m-d'), 'starts' => ['09:00']],
        ]);

        $pub->update(['expires_at' => now()->subDay()]);

        $response = $this->get("/book/{$this->master->master_slug}?service_id={$this->masterService->id}&fw={$pub->token}");
        $response->assertOk();

        $props = $this->extractInertiaProps($response);
        $this->assertTrue($props['publicationContext']['active']);
        $this->assertTrue($props['publicationContext']['expired']);
    }

    public function test_expired_available_dates_returns_empty(): void
    {
        $date = $this->getNextWeekday();

        $pub = $this->createPublication('service', $date, [
            ['date' => $date->format('Y-m-d'), 'starts' => ['09:00']],
        ]);
        $pub->update(['expires_at' => now()->subDay()]);

        // available-dates is a GET route
        $response = $this->getJson("/book/{$this->master->master_slug}/available-dates?service_id={$this->masterService->id}&year={$date->year}&month={$date->month}&fw={$pub->token}");

        $response->assertOk();
        $this->assertEmpty($response->json('dates'));
        $this->assertTrue($response->json('publicationExpired'));
    }

    public function test_expired_store_returns_422(): void
    {
        $date = $this->getNextWeekday();

        $pub = $this->createPublication('service', $date, [
            ['date' => $date->format('Y-m-d'), 'starts' => ['09:00']],
        ]);
        $pub->update(['expires_at' => now()->subDay()]);

        $response = $this->postJson("/book/{$this->master->master_slug}", [
            'service_id' => $this->masterService->id,
            'date' => $date->format('Y-m-d'),
            'time' => '09:00',
            'provider' => 'vk',
            'fw' => $pub->token,
        ]);

        $response->assertStatus(422);
    }

    public function test_expired_does_not_fallback_to_full_calendar(): void
    {
        $date = $this->getNextWeekday();

        $pub = $this->createPublication('service', $date, [
            ['date' => $date->format('Y-m-d'), 'starts' => ['09:00']],
        ]);
        $pub->update(['expires_at' => now()->subDay()]);

        $response = $this->get("/book/{$this->master->master_slug}?service_id={$this->masterService->id}&date={$date->format('Y-m-d')}&fw={$pub->token}");
        $response->assertOk();

        // When expired, availableSlots should be null (not computed)
        $response->assertInertia(fn (Assert $page) => $page
            ->component('booking/widget')
            ->missing('availableSlots')
        );
    }

    // ═══════════════════════════════════════════════════════
    // §32: Cross-master / tampering
    // ═══════════════════════════════════════════════════════
    public function test_fw_master_a_with_slug_master_b_rejected(): void
    {
        $date = $this->getNextWeekday();

        $otherMaster = User::factory()->master()->create([
            'is_service_provider' => true,
            'settings' => ['timezone' => 'Europe/Moscow'],
            'master_slug' => 'other-master',
        ]);
        $otherWorkspace = Workspace::create(['name' => 'Other', 'owner_id' => $otherMaster->id]);
        $otherMaster->update(['workspace_id' => $otherWorkspace->id]);
        // Other master needs at least one active service to be visible in widget
        $otherCatalog = ServiceCatalog::create([
            'workspace_id' => $otherWorkspace->id, 'title' => 'Other', 'base_duration' => 60, 'base_price' => 1000, 'is_active' => true,
        ]);
        MasterService::create([
            'master_id' => $otherMaster->id, 'catalog_id' => $otherCatalog->id, 'effective_duration' => 60, 'is_active' => true,
        ]);

        $pub = $this->createPublication('service', $date, [
            ['date' => $date->format('Y-m-d'), 'starts' => ['09:00']],
        ]);

        $response = $this->get("/book/{$otherMaster->master_slug}?service_id={$this->masterService->id}&fw={$pub->token}");
        $response->assertOk();

        $props = $this->extractInertiaProps($response);
        $this->assertTrue($props['publicationContext']['expired']);
    }

    public function test_unknown_fw_rejected(): void
    {
        $date = $this->getNextWeekday();

        $response = $this->get("/book/{$this->master->master_slug}?service_id={$this->masterService->id}&fw=nonexistent_token");
        $response->assertOk();

        $props = $this->extractInertiaProps($response);
        $this->assertTrue($props['publicationContext']['expired']);
    }

    public function test_manual_date_outside_snapshot_rejected(): void
    {
        $date = $this->getNextWeekday();

        $pub = $this->createPublication('service', $date, [
            ['date' => $date->format('Y-m-d'), 'starts' => ['09:00']],
        ]);

        $response = $this->postJson("/book/{$this->master->master_slug}", [
            'service_id' => $this->masterService->id,
            'date' => $date->copy()->addDays(10)->format('Y-m-d'),
            'time' => '09:00',
            'provider' => 'vk',
            'fw' => $pub->token,
        ]);

        $response->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════
    // Cleanup command
    // ═══════════════════════════════════════════════════════
    public function test_cleanup_deletes_expired(): void
    {
        $date = $this->getNextWeekday();

        $pub = $this->createPublication('service', $date, [
            ['date' => $date->format('Y-m-d'), 'starts' => ['09:00']],
        ]);
        $pub->update(['expires_at' => now()->subDay()]);

        $this->artisan('publications:cleanup-expired');

        $this->assertDatabaseMissing('free_window_publications', ['id' => $pub->id]);
    }

    public function test_cleanup_keeps_valid(): void
    {
        $date = $this->getNextWeekday();

        $pub = $this->createPublication('service', $date, [
            ['date' => $date->format('Y-m-d'), 'starts' => ['09:00']],
        ]);

        $this->artisan('publications:cleanup-expired');

        $this->assertDatabaseHas('free_window_publications', ['id' => $pub->id]);
    }

    // ═══════════════════════════════════════════════════════
    // §20: Route exists
    // ═══════════════════════════════════════════════════════
    public function test_publication_store_route_exists(): void
    {
        $this->assertTrue(Route::has('admin.free-windows.publications.store'));
    }

    public function test_publication_store_requires_auth(): void
    {
        $response = $this->postJson('/admin/free-windows/publications', []);
        $response->assertRedirect();
    }

    public function test_publication_store_requires_pro_feature(): void
    {
        $startPlan = TariffPlan::create([
            'code' => 'start', 'name' => 'Старт', 'price_monthly' => 0,
            'features' => ['calendar'], 'is_active' => true,
        ]);
        $startMaster = User::factory()->master()->create([
            'is_service_provider' => true, 'settings' => ['timezone' => 'Europe/Moscow'],
        ]);
        $startWorkspace = Workspace::create(['name' => 'Start', 'owner_id' => $startMaster->id]);
        $startMaster->update(['workspace_id' => $startWorkspace->id]);
        Subscription::create([
            'workspace_id' => $startWorkspace->id, 'tariff_plan_id' => $startPlan->id,
            'status' => 'active', 'expires_at' => now()->addYear(),
        ]);

        $this->actingAs($startMaster);

        $response = $this->postJson('/admin/free-windows/publications', [
            'mode' => 'all', 'date_from' => now()->format('Y-m-d'),
            'date_to' => now()->format('Y-m-d'),
            'days' => [['date' => now()->format('Y-m-d'), 'ranges' => [['start' => '09:00', 'end' => '18:00']]]],
        ]);

        $response->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════
    private function getNextWeekday(): Carbon
    {
        $date = Carbon::now('Europe/Moscow')->addDay()->startOfDay();
        while ($date->isWeekend()) {
            $date->addDay();
        }

        return $date;
    }

    private function createPublication(
        string $mode,
        Carbon $date,
        array $days,
        ?string $serviceId = null,
    ): FreeWindowPublication {
        $expiresAt = $date->copy()->endOfDay()->utc();

        return FreeWindowPublication::create([
            'master_id' => $this->master->id,
            'token' => \Illuminate\Support\Str::random(28),
            'content_hash' => hash('sha256', json_encode($days)),
            'mode' => $mode,
            'master_service_id' => $serviceId,
            'date_from' => $date->format('Y-m-d'),
            'date_to' => $date->format('Y-m-d'),
            'payload' => ['days' => $days],
            'expires_at' => $expiresAt,
            'created_at' => now(),
        ]);
    }

    private function extractInertiaProps($response): array
    {
        $props = [];
        $response->assertInertia(function (Assert $page) use (&$props) {
            $props = $page->toArray()['props'] ?? [];
            return $page->reloadOnly('availableSlots', fn (Assert $r) => $r->etc())
                ->reloadOnly('publicationContext', fn (Assert $r) => $r->etc())
                ->etc();
        });

        return $props;
    }
}
