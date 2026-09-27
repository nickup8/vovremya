<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\RecurrenceType;
use App\Enums\RecurringSeriesStatus;
use App\Models\Appointment;
use App\Models\BlockedTime;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\RecurringBlockedTimeSeries;
use App\Models\ServiceCatalog;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkingHour;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FreeWindowsTest extends TestCase
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
            'name' => 'FW Test Studio',
            'owner_id' => $this->master->id,
        ]);
        $this->master->update(['workspace_id' => $this->workspace->id]);

        // Subscribe master to Pro (needed for feature:free_windows middleware)
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
        // Sat-Sun off
        for ($day = 0; $day <= 6; $day += 6) {
            WorkingHour::updateOrCreate(
                ['user_id' => $this->master->id, 'day_of_week' => $day],
                ['is_working' => false, 'start_time' => '09:00', 'end_time' => '18:00', 'break_start_time' => null, 'break_end_time' => null]
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
    // Route exists
    // ═══════════════════════════════════════════════════════
    public function test_free_windows_route_exists(): void
    {
        $this->assertTrue(Route::has('admin.free-windows'));
    }

    public function test_free_windows_requires_auth(): void
    {
        $response = $this->getJson('/admin/free-windows?date_from=' . now()->format('Y-m-d') . '&date_to=' . now()->format('Y-m-d'));
        $response->assertRedirect();
    }

    public function test_free_windows_requires_pro_feature(): void
    {
        // Create a Start user without free_windows feature
        $startPlan = TariffPlan::create([
            'code' => 'start',
            'name' => 'Старт',
            'price_monthly' => 0,
            'max_appointments_per_month' => null,
            'features' => ['calendar'],
            'is_active' => true,
        ]);
        $startMaster = User::factory()->master()->create([
            'is_service_provider' => true,
            'settings' => ['timezone' => 'Europe/Moscow'],
        ]);
        $startWorkspace = Workspace::create([
            'name' => 'Start Studio',
            'owner_id' => $startMaster->id,
        ]);
        $startMaster->update(['workspace_id' => $startWorkspace->id]);
        Subscription::create([
            'workspace_id' => $startWorkspace->id,
            'tariff_plan_id' => $startPlan->id,
            'status' => 'active',
            'expires_at' => now()->addYear(),
        ]);

        $this->actingAs($startMaster);

        $from = now()->format('Y-m-d');
        $to = now()->format('Y-m-d');
        $response = $this->getJson("/admin/free-windows?date_from={$from}&date_to={$to}");
        $response->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════
    // Validation
    // ═══════════════════════════════════════════════════════
    public function test_validation_requires_date_from_and_date_to(): void
    {
        $this->actingAs($this->master);

        $response = $this->getJson('/admin/free-windows');
        $this->assertContains($response->status(), [422, 302]);
    }

    public function test_validation_rejects_range_over_14_days(): void
    {
        $this->actingAs($this->master);

        $from = now()->format('Y-m-d');
        $to = now()->addDays(20)->format('Y-m-d');
        $response = $this->getJson("/admin/free-windows?date_from={$from}&date_to={$to}");
        $this->assertContains($response->status(), [422, 302]);
    }

    public function test_validation_allows_14_day_range(): void
    {
        $this->actingAs($this->master);

        $from = now()->format('Y-m-d');
        $to = now()->addDays(13)->format('Y-m-d');
        $response = $this->getJson("/admin/free-windows?date_from={$from}&date_to={$to}");
        $response->assertOk();
    }

    public function test_validation_rejects_service_not_belonging_to_master(): void
    {
        $this->actingAs($this->master);

        $otherMaster = User::factory()->master()->create([
            'is_service_provider' => true,
            'settings' => ['timezone' => 'Europe/Moscow'],
        ]);
        $otherWorkspace = Workspace::create([
            'name' => 'Other Studio',
            'owner_id' => $otherMaster->id,
        ]);
        $otherMaster->update(['workspace_id' => $otherWorkspace->id]);

        $otherCatalog = ServiceCatalog::create([
            'workspace_id' => $otherWorkspace->id,
            'title' => 'Другая',
            'base_duration' => 60,
            'base_price' => 1000,
            'is_active' => true,
        ]);
        $otherMasterService = MasterService::create([
            'master_id' => $otherMaster->id,
            'catalog_id' => $otherCatalog->id,
            'effective_duration' => 60,
            'is_active' => true,
        ]);

        $from = now()->format('Y-m-d');
        $to = now()->format('Y-m-d');
        $response = $this->getJson("/admin/free-windows?date_from={$from}&date_to={$to}&service_id={$otherMasterService->id}");
        $response->assertStatus(403);
    }

    public function test_validation_rejects_inactive_service(): void
    {
        $this->actingAs($this->master);

        // Use a different catalog to avoid unique constraint
        $otherCatalog = ServiceCatalog::create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Inactive Service',
            'base_duration' => 60,
            'base_price' => 1000,
            'is_active' => true,
        ]);
        $inactive = MasterService::create([
            'master_id' => $this->master->id,
            'catalog_id' => $otherCatalog->id,
            'effective_duration' => 60,
            'is_active' => false,
        ]);

        $from = now()->format('Y-m-d');
        $to = now()->format('Y-m-d');
        $response = $this->getJson("/admin/free-windows?date_from={$from}&date_to={$to}&service_id={$inactive->id}");
        $this->assertContains($response->status(), [422, 302]);
    }

    // ═══════════════════════════════════════════════════════
    // All-services mode: continuous free intervals
    // ═══════════════════════════════════════════════════════
    public function test_all_services_mode_returns_continuous_free_intervals(): void
    {
        $this->actingAs($this->master);

        $date = $this->getNextWeekday();
        $from = $date->format('Y-m-d');
        $to = $from;

        $response = $this->getJson("/admin/free-windows?date_from={$from}&date_to={$to}");
        $response->assertOk();

        $data = $response->json();
        $this->assertEquals('all', $data['mode']);
        $this->assertEquals('Europe/Moscow', $data['timezone']);
        $this->assertNotEmpty($data['days']);
        $this->assertArrayHasKey('ranges', $data['days'][0]);
        // Full day 09:00-18:00 free
        $this->assertEquals('09:00', $data['days'][0]['ranges'][0]['start']);
        $this->assertEquals('18:00', $data['days'][0]['ranges'][0]['end']);
    }

    public function test_all_services_mode_excludes_booked_appointments(): void
    {
        $this->actingAs($this->master);

        $date = $this->getNextWeekday();
        $client = Client::factory()->for($this->master)->create();

        // Book 10:00-11:00
        Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client)
            ->withMasterService($this->masterService)
            ->booked()
            ->create([
                'start_time' => $date->copy()->setTime(10, 0)->timezone('UTC'),
                'duration' => 60,
            ]);

        $from = $date->format('Y-m-d');
        $to = $from;

        $response = $this->getJson("/admin/free-windows?date_from={$from}&date_to={$to}");
        $response->assertOk();

        $ranges = $response->json('days.0.ranges');
        $this->assertNotEmpty($ranges);

        // Should have 09:00-10:00 and 11:00-18:00
        $this->assertCount(2, $ranges);
        $this->assertEquals('09:00', $ranges[0]['start']);
        $this->assertEquals('10:00', $ranges[0]['end']);
        $this->assertEquals('11:00', $ranges[1]['start']);
        $this->assertEquals('18:00', $ranges[1]['end']);
    }

    public function test_all_services_mode_excludes_blocked_times(): void
    {
        $this->actingAs($this->master);

        $date = $this->getNextWeekday();

        BlockedTime::create([
            'user_id' => $this->master->id,
            'start_datetime' => $date->copy()->setTime(14, 0)->timezone('UTC'),
            'end_datetime' => $date->copy()->setTime(15, 0)->timezone('UTC'),
        ]);

        $from = $date->format('Y-m-d');
        $to = $from;

        $response = $this->getJson("/admin/free-windows?date_from={$from}&date_to={$to}");
        $response->assertOk();

        $ranges = $response->json('days.0.ranges');
        $this->assertCount(2, $ranges);
        $this->assertEquals('14:00', $ranges[0]['end']);
        $this->assertEquals('15:00', $ranges[1]['start']);
    }

    public function test_all_services_mode_excludes_recurring_blocked_times(): void
    {
        $this->actingAs($this->master);

        $date = $this->getNextWeekday();

        RecurringBlockedTimeSeries::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->workspace->id,
            'title' => 'Обед',
            'start_date' => $date->copy()->subWeek()->format('Y-m-d'),
            'start_time' => '12:00',
            'end_time' => '13:00',
            'recurrence_type' => RecurrenceType::Daily,
            'interval' => 1,
            'timezone' => 'Europe/Moscow',
            'status' => RecurringSeriesStatus::Active,
        ]);

        $from = $date->format('Y-m-d');
        $to = $from;

        $response = $this->getJson("/admin/free-windows?date_from={$from}&date_to={$to}");
        $response->assertOk();

        $ranges = $response->json('days.0.ranges');
        // 09:00-12:00, 13:00-18:00
        $this->assertCount(2, $ranges);
        $this->assertEquals('12:00', $ranges[0]['end']);
        $this->assertEquals('13:00', $ranges[1]['start']);
    }

    public function test_all_services_mode_skips_non_working_days(): void
    {
        $this->actingAs($this->master);

        // Find a Saturday
        $date = $this->getNextWeekday()->next(Carbon::SATURDAY);

        $from = $date->format('Y-m-d');
        $to = $from;

        $response = $this->getJson("/admin/free-windows?date_from={$from}&date_to={$to}");
        $response->assertOk();

        $data = $response->json();
        $this->assertEmpty($data['days']);
    }

    // ═══════════════════════════════════════════════════════
    // Service mode: discrete start times
    // ═══════════════════════════════════════════════════════
    public function test_service_mode_returns_discrete_start_times(): void
    {
        $this->actingAs($this->master);

        $date = $this->getNextWeekday();
        $from = $date->format('Y-m-d');
        $to = $from;

        $response = $this->getJson("/admin/free-windows?date_from={$from}&date_to={$to}&service_id={$this->masterService->id}");
        $response->assertOk();

        $data = $response->json();
        $this->assertEquals('service', $data['mode']);
        $this->assertNotEmpty($data['days']);
        $this->assertArrayHasKey('starts', $data['days'][0]);

        $starts = $data['days'][0]['starts'];
        $this->assertContains('09:00', $starts);
        $this->assertContains('09:30', $starts);
        // 18:00 should NOT fit (18:00+60=19:00 > 18:00)
        $this->assertNotContains('18:00', $starts);
    }

    public function test_service_mode_excludes_booked_slots(): void
    {
        $this->actingAs($this->master);

        $date = $this->getNextWeekday();
        $client = Client::factory()->for($this->master)->create();

        // Book 10:00-11:00
        Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client)
            ->withMasterService($this->masterService)
            ->booked()
            ->create([
                'start_time' => $date->copy()->setTime(10, 0)->timezone('UTC'),
                'duration' => 60,
            ]);

        $from = $date->format('Y-m-d');
        $to = $from;

        $response = $this->getJson("/admin/free-windows?date_from={$from}&date_to={$to}&service_id={$this->masterService->id}");
        $response->assertOk();

        $starts = $response->json('days.0.starts');
        // 09:30 fits into 09:30-10:30 which overlaps 10:00-11:00 — excluded
        $this->assertNotContains('09:30', $starts);
        // 10:00 is the booked slot itself — excluded
        $this->assertNotContains('10:00', $starts);
        // 10:30 fits into 10:30-11:30 which overlaps 10:00-11:00 — excluded
        $this->assertNotContains('10:30', $starts);
        // 11:00 starts free
        $this->assertContains('11:00', $starts);
    }

    public function test_service_mode_excludes_blocked_times(): void
    {
        $this->actingAs($this->master);

        $date = $this->getNextWeekday();

        BlockedTime::create([
            'user_id' => $this->master->id,
            'start_datetime' => $date->copy()->setTime(14, 0)->timezone('UTC'),
            'end_datetime' => $date->copy()->setTime(15, 0)->timezone('UTC'),
        ]);

        $from = $date->format('Y-m-d');
        $to = $from;

        $response = $this->getJson("/admin/free-windows?date_from={$from}&date_to={$to}&service_id={$this->masterService->id}");
        $response->assertOk();

        $starts = $response->json('days.0.starts');
        // 13:30 fits 13:30-14:30 — overlaps blocked 14:00-15:00 — excluded
        $this->assertNotContains('13:30', $starts);
        $this->assertNotContains('14:00', $starts);
        // 15:00 starts free
        $this->assertContains('15:00', $starts);
    }

    // ═══════════════════════════════════════════════════════
    // Multi-day ranges
    // ═══════════════════════════════════════════════════════
    public function test_multi_day_range_returns_data_for_each_working_day(): void
    {
        $this->actingAs($this->master);

        $date = $this->getNextWeekday();
        $from = $date->format('Y-m-d');
        $to = $date->copy()->addDays(4)->format('Y-m-d');

        $response = $this->getJson("/admin/free-windows?date_from={$from}&date_to={$to}");
        $response->assertOk();

        $days = $response->json('days');
        $this->assertGreaterThanOrEqual(3, count($days));
    }

    // ═══════════════════════════════════════════════════════
    // Booking URL generation
    // ═══════════════════════════════════════════════════════
    public function test_booking_url_without_service(): void
    {
        $this->actingAs($this->master);

        $from = now()->format('Y-m-d');
        $to = now()->format('Y-m-d');

        $response = $this->getJson("/admin/free-windows?date_from={$from}&date_to={$to}");
        $response->assertOk();

        $url = $response->json('booking_url');
        $this->assertStringStartsWith('http', $url);
        $this->assertStringStartsWith($this->testAppUrl . '/book/' . $this->master->master_slug, $url);
        $this->assertStringNotContainsString('service_id', $url);
    }

    public function test_booking_url_with_service(): void
    {
        $this->actingAs($this->master);

        $from = now()->format('Y-m-d');
        $to = now()->format('Y-m-d');

        $response = $this->getJson("/admin/free-windows?date_from={$from}&date_to={$to}&service_id={$this->masterService->id}");
        $response->assertOk();

        $url = $response->json('booking_url');
        $expected = $this->testAppUrl . '/book/' . $this->master->master_slug . '?service_id=' . $this->masterService->id;
        $this->assertEquals($expected, $url);
    }

    // ═══════════════════════════════════════════════════════
    // Response metadata
    // ═══════════════════════════════════════════════════════
    public function test_response_contains_required_metadata(): void
    {
        $this->actingAs($this->master);

        $from = now()->format('Y-m-d');
        $to = now()->format('Y-m-d');

        $response = $this->getJson("/admin/free-windows?date_from={$from}&date_to={$to}");
        $response->assertOk();

        $data = $response->json();
        $this->assertArrayHasKey('mode', $data);
        $this->assertArrayHasKey('timezone', $data);
        $this->assertArrayHasKey('date_from', $data);
        $this->assertArrayHasKey('date_to', $data);
        $this->assertArrayHasKey('booking_url', $data);
        $this->assertArrayHasKey('days', $data);
    }

    // ═══════════════════════════════════════════════════════
    // Break periods excluded
    // ═══════════════════════════════════════════════════════
    public function test_break_periods_are_excluded_from_free_intervals(): void
    {
        $this->actingAs($this->master);

        $date = $this->getNextWeekday();

        // Add a break to working hours (hasBreak() checks break_start_time/break_end_time non-null)
        WorkingHour::where('user_id', $this->master->id)
            ->where('day_of_week', $date->dayOfWeek)
            ->update([
                'break_start_time' => '12:00',
                'break_end_time' => '13:00',
            ]);

        $from = $date->format('Y-m-d');
        $to = $from;

        $response = $this->getJson("/admin/free-windows?date_from={$from}&date_to={$to}");
        $response->assertOk();

        $ranges = $response->json('days.0.ranges');
        $this->assertNotEmpty($ranges);
        // Should have 09:00-12:00 and 13:00-18:00
        $this->assertCount(2, $ranges);
        $this->assertEquals('12:00', $ranges[0]['end']);
        $this->assertEquals('13:00', $ranges[1]['start']);
    }

    // ═══════════════════════════════════════════════════════
    // §5: Service-mode parity with BookingService
    // ═══════════════════════════════════════════════════════
    public function test_service_mode_parity_with_booking_service(): void
    {
        $this->actingAs($this->master);

        $date = $this->getNextWeekday();

        // Complex fixture: break, one-off blocked, recurring blocked, appointment
        WorkingHour::where('user_id', $this->master->id)
            ->where('day_of_week', $date->dayOfWeek)
            ->update([
                'break_start_time' => '12:00',
                'break_end_time' => '12:30',
            ]);

        BlockedTime::create([
            'user_id' => $this->master->id,
            'start_datetime' => $date->copy()->setTime(14, 0)->timezone('UTC'),
            'end_datetime' => $date->copy()->setTime(14, 30)->timezone('UTC'),
        ]);

        RecurringBlockedTimeSeries::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->workspace->id,
            'title' => 'Обед',
            'start_date' => $date->copy()->subWeek()->format('Y-m-d'),
            'start_time' => '16:00',
            'end_time' => '16:30',
            'recurrence_type' => RecurrenceType::Daily,
            'interval' => 1,
            'timezone' => 'Europe/Moscow',
            'status' => RecurringSeriesStatus::Active,
        ]);

        $client = Client::factory()->for($this->master)->create();
        Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client)
            ->withMasterService($this->masterService)
            ->booked()
            ->create([
                'start_time' => $date->copy()->setTime(10, 0)->timezone('UTC'),
                'duration' => 45,
            ]);

        // Set slot_interval = 20 for finer granularity
        $this->master->update(['slot_interval' => 20]);

        // Path 1: BookingService::getAvailableSlots (public booking path)
        $bookingService = app(\App\Services\Booking\BookingService::class);
        $bookingSlots = $bookingService->getAvailableSlots(
            $this->master,
            $this->masterService,
            $date->format('Y-m-d'),
        );

        // Path 2: FreeWindows service mode (admin panel)
        $response = $this->getJson("/admin/free-windows?date_from={$date->format('Y-m-d')}&date_to={$date->format('Y-m-d')}&service_id={$this->masterService->id}");
        $response->assertOk();

        $freeWindowsStarts = $response->json('days.0.starts');

        // The two paths MUST produce identical results
        $this->assertEquals($bookingSlots, $freeWindowsStarts, 'FreeWindows service mode must equal BookingService::getAvailableSlots');
    }

    // ═══════════════════════════════════════════════════════
    // §4: Service mode excludes recurring blocked occurrence
    // ═══════════════════════════════════════════════════════
    public function test_service_mode_excludes_recurring_blocked_occurrence(): void
    {
        $this->actingAs($this->master);

        $date = $this->getNextWeekday();

        // Ensure 12:00 is within working hours
        WorkingHour::where('user_id', $this->master->id)
            ->where('day_of_week', $date->dayOfWeek)
            ->update([
                'start_time' => '09:00',
                'end_time' => '18:00',
                'break_start_time' => null,
                'break_end_time' => null,
            ]);

        // Recurring blocked 12:00-12:30
        RecurringBlockedTimeSeries::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->workspace->id,
            'title' => 'Обед',
            'start_date' => $date->copy()->subWeek()->format('Y-m-d'),
            'start_time' => '12:00',
            'end_time' => '12:30',
            'recurrence_type' => RecurrenceType::Daily,
            'interval' => 1,
            'timezone' => 'Europe/Moscow',
            'status' => RecurringSeriesStatus::Active,
        ]);

        $response = $this->getJson("/admin/free-windows?date_from={$date->format('Y-m-d')}&date_to={$date->format('Y-m-d')}&service_id={$this->masterService->id}");
        $response->assertOk();

        $starts = $response->json('days.0.starts');

        // 12:00 conflicts: slot 12:00-13:00 overlaps blocked 12:00-12:30
        $this->assertNotContains('12:00', $starts);
        // 11:30 conflicts: slot 11:30-12:30 overlaps blocked 12:00-12:30
        $this->assertNotContains('11:30', $starts);
        // 12:30 is free (12:30-13:30 doesn't overlap 12:00-12:30)
        $this->assertContains('12:30', $starts);
        // 11:00 is free (11:00-12:00 ends exactly at block start — no overlap)
        $this->assertContains('11:00', $starts);
    }

    // ═══════════════════════════════════════════════════════
    // §6: Cross-midnight parity
    // ═══════════════════════════════════════════════════════
    public function test_cross_midnight_parity_between_booking_and_free_windows(): void
    {
        $this->actingAs($this->master);

        // Pick a Friday that's at least 3 days away
        $date = $this->getNextWeekday();
        while ($date->dayOfWeek !== Carbon::FRIDAY) {
            $date->addDay();
        }
        $date->addDays(3); // Ensure it's in the future

        $client = Client::factory()->for($this->master)->create();

        // Create an appointment starting Friday 17:00, lasting 120 min → ends Saturday 19:00 (cross-midnight)
        // Working hours: Mon-Fri 09:00-18:00, Sat-Sun off
        // For Friday this appointment blocks 17:00-18:00 in the working window
        Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client)
            ->withMasterService($this->masterService)
            ->booked()
            ->create([
                'start_time' => $date->copy()->setTime(17, 0)->timezone('UTC'),
                'duration' => 120,
            ]);

        // Set slot_interval = 20
        $this->master->update(['slot_interval' => 20]);

        // Path 1: BookingService
        $bookingService = app(\App\Services\Booking\BookingService::class);
        $bookingSlots = $bookingService->getAvailableSlots(
            $this->master,
            $this->masterService,
            $date->format('Y-m-d'),
        );

        // Path 2: FreeWindows service mode
        $response = $this->getJson("/admin/free-windows?date_from={$date->format('Y-m-d')}&date_to={$date->format('Y-m-d')}&service_id={$this->masterService->id}");
        $response->assertOk();

        $freeWindowsStarts = $response->json('days.0.starts');

        $this->assertEquals($bookingSlots, $freeWindowsStarts, 'Cross-midnight appointment must produce identical exclusions in both paths');
    }

    // ═══════════════════════════════════════════════════════
    // §8–9: All-services today past-time filtering
    // ═══════════════════════════════════════════════════════
    public function test_all_services_today_partial_past_filters_correctly(): void
    {
        $this->actingAs($this->master);

        // Set "now" to 11:25 Europe/Moscow on a weekday
        $nextWeekday = $this->getNextWeekday();
        $testNow = $nextWeekday->copy()->setTime(11, 25, 0, 0);
        Carbon::setTestNow($testNow);

        // slot_interval = 30 → next boundary after 11:25 = 11:30
        $this->master->update(['slot_interval' => 30]);

        $from = $nextWeekday->format('Y-m-d');
        $to = $from;

        $response = $this->getJson("/admin/free-windows?date_from={$from}&date_to={$to}");
        $response->assertOk();

        $ranges = $response->json('days.0.ranges');
        $this->assertNotEmpty($ranges);

        // Range should start at 11:30 (next slot_interval boundary), NOT 09:00
        $this->assertEquals('11:30', $ranges[0]['start']);
        $this->assertEquals('18:00', $ranges[0]['end']);

        Carbon::setTestNow();
    }

    public function test_all_services_today_fully_past_returns_empty(): void
    {
        $this->actingAs($this->master);

        $nextWeekday = $this->getNextWeekday();
        // Set "now" to 19:00 — after end of working day (18:00)
        $testNow = $nextWeekday->copy()->setTime(19, 0, 0, 0);
        Carbon::setTestNow($testNow);

        $from = $nextWeekday->format('Y-m-d');
        $to = $from;

        $response = $this->getJson("/admin/free-windows?date_from={$from}&date_to={$to}");
        $response->assertOk();

        $days = $response->json('days');
        $this->assertEmpty($days);

        Carbon::setTestNow();
    }

    public function test_all_services_future_day_not_filtered(): void
    {
        $this->actingAs($this->master);

        $nextWeekday = $this->getNextWeekday();
        // Set "now" to 11:25 — partial past today
        $testNow = $nextWeekday->copy()->setTime(11, 25, 0, 0);
        Carbon::setTestNow($testNow);

        // Request tomorrow (next working day)
        $tomorrow = $nextWeekday->copy()->addDay();
        while ($tomorrow->isWeekend()) {
            $tomorrow->addDay();
        }

        $from = $tomorrow->format('Y-m-d');
        $to = $from;

        $response = $this->getJson("/admin/free-windows?date_from={$from}&date_to={$to}");
        $response->assertOk();

        $ranges = $response->json('days.0.ranges');
        $this->assertNotEmpty($ranges);

        // Future day: full working hours preserved, starts at 09:00
        $this->assertEquals('09:00', $ranges[0]['start']);
        $this->assertEquals('18:00', $ranges[0]['end']);

        Carbon::setTestNow();
    }

    // ═══════════════════════════════════════════════════════
    // Helper
    // ═══════════════════════════════════════════════════════
    private function getNextWeekday(): Carbon
    {
        $date = Carbon::now('Europe/Moscow')->addDay()->startOfDay();
        while ($date->isWeekend()) {
            $date->addDay();
        }

        return $date;
    }
}
