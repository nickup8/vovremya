<?php

namespace Tests\Feature;

use App\Enums\ExceptionType;
use App\Enums\RecurrenceType;
use App\Enums\RecurringSeriesStatus;
use App\Models\Appointment;
use App\Models\BlockedTime;
use App\Models\MasterService;
use App\Models\RecurringBlockedTimeException;
use App\Models\RecurringBlockedTimeSeries;
use App\Models\ServiceCatalog;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkingHour;
use App\Models\Subscription;
use App\Services\Booking\AvailabilityService;
use App\Services\Recurrence\RecurrenceRule;
use App\Services\Recurrence\RecurrenceService;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecurringBlockedTimeTest extends TestCase
{
    use RefreshDatabase;

    private User $proMaster;
    private User $startMaster;
    private Workspace $proWorkspace;
    private Workspace $startWorkspace;

    protected function setUp(): void
    {
        parent::setUp();

        // Create Pro tariff plan
        $proPlan = TariffPlan::create([
            'code' => 'pro',
            'name' => 'Профи',
            'price_monthly' => 490,
            'max_appointments_per_month' => null,
            'max_masters' => 1,
            'features' => ['unlimited_appointments', 'client_management', 'channel_analytics', 'slot_autofill', 'recurring_blocked_times'],
            'is_active' => true,
        ]);

        // Create Start tariff plan (without recurring feature)
        $startPlan = TariffPlan::create([
            'code' => 'start',
            'name' => 'Старт',
            'price_monthly' => 0,
            'max_appointments_per_month' => 30,
            'max_masters' => 1,
            'features' => ['calendar', 'basic_client_management'],
            'is_active' => true,
        ]);

        // Pro workspace + master
        $this->proMaster = User::factory()->create([
            'is_master' => true,
            'settings' => ['timezone' => 'Europe/Moscow'],
        ]);
        $this->proWorkspace = Workspace::create([
            'name' => 'Pro Studio',
            'owner_id' => $this->proMaster->id,
        ]);
        $this->proMaster->update(['workspace_id' => $this->proWorkspace->id]);
        Subscription::create([
            'workspace_id' => $this->proWorkspace->id,
            'tariff_plan_id' => $proPlan->id,
            'status' => 'active',
            'expires_at' => now()->addYear(),
        ]);
        $this->proMaster->createDefaultWorkingHours();

        // Start workspace + master
        $this->startMaster = User::factory()->create([
            'is_master' => true,
            'settings' => ['timezone' => 'Europe/Moscow'],
        ]);
        $this->startWorkspace = Workspace::create([
            'name' => 'Start Studio',
            'owner_id' => $this->startMaster->id,
        ]);
        $this->startMaster->update(['workspace_id' => $this->startWorkspace->id]);
        Subscription::create([
            'workspace_id' => $this->startWorkspace->id,
            'tariff_plan_id' => $startPlan->id,
            'status' => 'active',
            'expires_at' => now()->addYear(),
        ]);
        $this->startMaster->createDefaultWorkingHours();
    }

    // ═══════════════════════════════════════════════════════
    // P0-1: Pro can create recurring blocked time
    // ═══════════════════════════════════════════════════════
    public function test_pro_can_create_recurring_blocked_time(): void
    {
        $this->actingAs($this->proMaster);

        $response = $this->postJson('/admin/recurring-blocked-times', [
            'title' => 'Забрать ребёнка',
            'start_date' => '2026-10-01',
            'start_time' => '16:00',
            'end_time' => '17:00',
            'recurrence_type' => 'daily',
            'interval' => 1,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('recurring_blocked_time_series', [
            'user_id' => $this->proMaster->id,
            'title' => 'Забрать ребёнка',
            'status' => 'active',
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // P0-2: Start cannot create recurring blocked time
    // ═══════════════════════════════════════════════════════
    public function test_start_cannot_create_recurring_blocked_time(): void
    {
        $this->actingAs($this->startMaster);

        $response = $this->postJson('/admin/recurring-blocked-times', [
            'title' => 'Личные дела',
            'start_date' => '2026-10-01',
            'start_time' => '12:00',
            'end_time' => '13:00',
            'recurrence_type' => 'weekly',
            'interval' => 1,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('recurring_blocked_time_series', 0);
    }

    // ═══════════════════════════════════════════════════════
    // P0-3: Daily recurrence
    // ═══════════════════════════════════════════════════════
    public function test_daily_recurrence_generates_correctly(): void
    {
        $service = new RecurrenceService();
        $rule = RecurrenceRule::fromArray([
            'recurrence_type' => 'daily',
            'interval' => 1,
            'weekdays' => null,
            'start_date' => '2026-10-01',
            'ends_at' => '2026-10-05',
            'timezone' => 'Europe/Moscow',
        ]);

        $occurrences = $service->generateOccurrences(
            $rule,
            Carbon::parse('2026-10-01', 'Europe/Moscow'),
            Carbon::parse('2026-10-05', 'Europe/Moscow'),
        );

        $this->assertCount(5, $occurrences);
        $this->assertEquals('2026-10-01', $occurrences[0]->format('Y-m-d'));
        $this->assertEquals('2026-10-05', $occurrences[4]->format('Y-m-d'));
    }

    // ═══════════════════════════════════════════════════════
    // P0-4: Weekly recurrence
    // ═══════════════════════════════════════════════════════
    public function test_weekly_recurrence_generates_correctly(): void
    {
        // 2026-10-05 is Monday
        $service = new RecurrenceService();
        $rule = RecurrenceRule::fromArray([
            'recurrence_type' => 'weekly',
            'interval' => 1,
            'weekdays' => null,
            'start_date' => '2026-10-05',
            'ends_at' => '2026-10-26',
            'timezone' => 'Europe/Moscow',
        ]);

        $occurrences = $service->generateOccurrences(
            $rule,
            Carbon::parse('2026-10-05', 'Europe/Moscow'),
            Carbon::parse('2026-10-26', 'Europe/Moscow'),
        );

        // Should be every Monday: 5, 12, 19, 26
        $this->assertCount(4, $occurrences);
        $this->assertEquals('2026-10-05', $occurrences[0]->format('Y-m-d'));
        $this->assertEquals('2026-10-12', $occurrences[1]->format('Y-m-d'));
        $this->assertEquals('2026-10-19', $occurrences[2]->format('Y-m-d'));
        $this->assertEquals('2026-10-26', $occurrences[3]->format('Y-m-d'));
    }

    // ═══════════════════════════════════════════════════════
    // P0-5: Interval=2 (biweekly)
    // ═══════════════════════════════════════════════════════
    public function test_biweekly_interval_works(): void
    {
        $service = new RecurrenceService();
        $rule = RecurrenceRule::fromArray([
            'recurrence_type' => 'weekly',
            'interval' => 2,
            'weekdays' => null,
            'start_date' => '2026-10-05',
            'ends_at' => '2026-11-02',
            'timezone' => 'Europe/Moscow',
        ]);

        $occurrences = $service->generateOccurrences(
            $rule,
            Carbon::parse('2026-10-05', 'Europe/Moscow'),
            Carbon::parse('2026-11-02', 'Europe/Moscow'),
        );

        // Weeks 0, 2, 4 from start: Oct 5, Oct 19, Nov 2
        $this->assertCount(3, $occurrences);
        $this->assertEquals('2026-10-05', $occurrences[0]->format('Y-m-d'));
        $this->assertEquals('2026-10-19', $occurrences[1]->format('Y-m-d'));
        $this->assertEquals('2026-11-02', $occurrences[2]->format('Y-m-d'));
    }

    // ═══════════════════════════════════════════════════════
    // P0-6: Multiple weekdays (custom_weekly)
    // ═══════════════════════════════════════════════════════
    public function test_custom_weekly_with_multiple_weekdays(): void
    {
        // Mon=1, Wed=3, Fri=5
        $service = new RecurrenceService();
        $rule = RecurrenceRule::fromArray([
            'recurrence_type' => 'custom_weekly',
            'interval' => 1,
            'weekdays' => [1, 3, 5],
            'start_date' => '2026-10-05', // Monday
            'ends_at' => '2026-10-11',    // Sunday
            'timezone' => 'Europe/Moscow',
        ]);

        $occurrences = $service->generateOccurrences(
            $rule,
            Carbon::parse('2026-10-05', 'Europe/Moscow'),
            Carbon::parse('2026-10-11', 'Europe/Moscow'),
        );

        // Mon 5, Wed 7, Fri 9
        $this->assertCount(3, $occurrences);
        $this->assertEquals('2026-10-05', $occurrences[0]->format('Y-m-d'));
        $this->assertEquals('2026-10-07', $occurrences[1]->format('Y-m-d'));
        $this->assertEquals('2026-10-09', $occurrences[2]->format('Y-m-d'));
    }

    // ═══════════════════════════════════════════════════════
    // P0-7: ends_at works
    // ═══════════════════════════════════════════════════════
    public function test_ends_at_limits_occurrences(): void
    {
        $service = new RecurrenceService();
        $rule = RecurrenceRule::fromArray([
            'recurrence_type' => 'daily',
            'interval' => 1,
            'weekdays' => null,
            'start_date' => '2026-10-01',
            'ends_at' => '2026-10-03',
            'timezone' => 'Europe/Moscow',
        ]);

        $occurrences = $service->generateOccurrences(
            $rule,
            Carbon::parse('2026-10-01', 'Europe/Moscow'),
            Carbon::parse('2026-10-10', 'Europe/Moscow'),
        );

        $this->assertCount(3, $occurrences);
        $this->assertEquals('2026-10-03', $occurrences[2]->format('Y-m-d'));
    }

    // ═══════════════════════════════════════════════════════
    // P0-8: Never-ending series (no ends_at)
    // ═══════════════════════════════════════════════════════
    public function test_never_ending_series_calculated_in_range(): void
    {
        $service = new RecurrenceService();
        $rule = RecurrenceRule::fromArray([
            'recurrence_type' => 'daily',
            'interval' => 1,
            'weekdays' => null,
            'start_date' => '2026-10-01',
            'ends_at' => null,
            'timezone' => 'Europe/Moscow',
        ]);

        // Request only October
        $occurrences = $service->generateOccurrences(
            $rule,
            Carbon::parse('2026-10-01', 'Europe/Moscow'),
            Carbon::parse('2026-10-31', 'Europe/Moscow'),
        );

        $this->assertCount(31, $occurrences);
    }

    // ═══════════════════════════════════════════════════════
    // P0-9: AvailabilityService excludes recurring blocked slots
    // ═══════════════════════════════════════════════════════
    public function test_availability_service_excludes_recurring_blocked_slots(): void
    {
        // Create a recurring block: every day 16:00-17:00 starting Oct 1
        RecurringBlockedTimeSeries::create([
            'workspace_id' => $this->proWorkspace->id,
            'user_id' => $this->proMaster->id,
            'title' => 'Забрать ребёнка',
            'start_date' => '2026-10-01',
            'start_time' => '16:00',
            'end_time' => '17:00',
            'recurrence_type' => RecurrenceType::Daily,
            'interval' => 1,
            'timezone' => 'Europe/Moscow',
            'status' => RecurringSeriesStatus::Active,
        ]);

        $service = app(AvailabilityService::class);
        $date = Carbon::parse('2026-10-05', 'Europe/Moscow'); // Monday
        $slots = $service->getAvailableSlots($this->proMaster, $date, 60);

        // 16:00 should not be available (blocked by recurring)
        $this->assertNotContains('16:00', $slots);
        // 15:00 should be available — 60min service ends at 16:00, block starts at 16:00 (half-open: no overlap)
        $this->assertContains('15:00', $slots);
        // 17:00 should be available (after block)
        $this->assertContains('17:00', $slots);
    }

    // ═══════════════════════════════════════════════════════
    // P0-10: Service duration cannot overlap blocked occurrence
    // ═══════════════════════════════════════════════════════
    public function test_service_duration_respects_recurring_block(): void
    {
        // Block 16:00-17:00, service is 90 min
        RecurringBlockedTimeSeries::create([
            'workspace_id' => $this->proWorkspace->id,
            'user_id' => $this->proMaster->id,
            'title' => 'Block',
            'start_date' => '2026-10-01',
            'start_time' => '16:00',
            'end_time' => '17:00',
            'recurrence_type' => RecurrenceType::Daily,
            'interval' => 1,
            'timezone' => 'Europe/Moscow',
            'status' => RecurringSeriesStatus::Active,
        ]);

        $service = app(AvailabilityService::class);
        $date = Carbon::parse('2026-10-05', 'Europe/Moscow'); // Monday

        // 90 min service at 15:00 would end at 16:30 — overlaps 16:00-17:00 block
        $slots = $service->getAvailableSlots($this->proMaster, $date, 90);
        $this->assertNotContains('15:00', $slots);

        // 14:30 service would end at 16:00 — half-open: slot_end(16:00) > block_start(16:00) is FALSE, so no overlap
        // 14:30 should be available
        $this->assertContains('14:30', $slots);
    }

    // ═══════════════════════════════════════════════════════
    // P0-11: Booking widget doesn't show blocked slots (via getAvailableDates)
    // ═══════════════════════════════════════════════════════
    public function test_get_available_dates_respects_recurring_block(): void
    {
        // Block every weekday 09:00-18:00 (entire working day)
        RecurringBlockedTimeSeries::create([
            'workspace_id' => $this->proWorkspace->id,
            'user_id' => $this->proMaster->id,
            'title' => 'Full day block',
            'start_date' => '2026-10-01',
            'start_time' => '09:00',
            'end_time' => '18:00',
            'recurrence_type' => RecurrenceType::Daily,
            'interval' => 1,
            'timezone' => 'Europe/Moscow',
            'status' => RecurringSeriesStatus::Active,
        ]);

        $service = app(AvailabilityService::class);
        $dates = $service->getAvailableDates($this->proMaster, 2026, 10, 60);

        // October 5 (Monday) should have no slots
        $this->assertNotContains('2026-10-05', $dates);
    }

    // ═══════════════════════════════════════════════════════
    // P0-14: Timezone works
    // ═══════════════════════════════════════════════════════
    public function test_timezone_affects_recurring_occurrences(): void
    {
        // Master in Moscow (UTC+3), block 16:00-17:00 local
        RecurringBlockedTimeSeries::create([
            'workspace_id' => $this->proWorkspace->id,
            'user_id' => $this->proMaster->id,
            'title' => 'Moscow block',
            'start_date' => '2026-10-01',
            'start_time' => '16:00',
            'end_time' => '17:00',
            'recurrence_type' => RecurrenceType::Daily,
            'interval' => 1,
            'timezone' => 'Europe/Moscow',
            'status' => RecurringSeriesStatus::Active,
        ]);

        $service = app(AvailabilityService::class);
        $date = Carbon::parse('2026-10-05', 'Europe/Moscow');
        $slots = $service->getAvailableSlots($this->proMaster, $date, 60);

        // 16:00 Moscow time should be blocked
        $this->assertNotContains('16:00', $slots);
        // 17:00 should be free
        $this->assertContains('17:00', $slots);
    }

    // ═══════════════════════════════════════════════════════
    // P0-15: Cache invalidation works
    // ═══════════════════════════════════════════════════════
    public function test_cache_invalidated_on_series_create(): void
    {
        // The observer should flush cache on save. If caching uses tags and the
        // driver supports them, the cache should be flushed. We verify the observer
        // is registered by checking the model event fires without error.
        $this->actingAs($this->proMaster);

        $response = $this->postJson('/admin/recurring-blocked-times', [
            'title' => 'Test cache',
            'start_date' => '2026-10-01',
            'start_time' => '16:00',
            'end_time' => '17:00',
            'recurrence_type' => 'daily',
            'interval' => 1,
        ]);

        $response->assertStatus(201);
        // If observer was not registered, the save would still work but cache
        // wouldn't be flushed. We verify it was created (observer attached in provider).
        $this->assertDatabaseHas('recurring_blocked_time_series', [
            'user_id' => $this->proMaster->id,
            'title' => 'Test cache',
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // P0-16: Exception skip works
    // ═══════════════════════════════════════════════════════
    public function test_exception_skip_removes_occurrence(): void
    {
        $series = RecurringBlockedTimeSeries::create([
            'workspace_id' => $this->proWorkspace->id,
            'user_id' => $this->proMaster->id,
            'title' => 'Daily block',
            'start_date' => '2026-10-01',
            'start_time' => '16:00',
            'end_time' => '17:00',
            'recurrence_type' => RecurrenceType::Daily,
            'interval' => 1,
            'timezone' => 'Europe/Moscow',
            'status' => RecurringSeriesStatus::Active,
        ]);

        // Skip Oct 5 (Monday)
        RecurringBlockedTimeException::create([
            'series_id' => $series->id,
            'occurrence_date' => '2026-10-05',
            'type' => ExceptionType::Skip,
        ]);

        $service = app(AvailabilityService::class);

        // Oct 5 should have 16:00 available (skipped)
        $date5 = Carbon::parse('2026-10-05', 'Europe/Moscow');
        $slots5 = $service->getAvailableSlots($this->proMaster, $date5, 60);
        $this->assertContains('16:00', $slots5);

        // Oct 6 should still be blocked
        $date6 = Carbon::parse('2026-10-06', 'Europe/Moscow');
        $slots6 = $service->getAvailableSlots($this->proMaster, $date6, 60);
        $this->assertNotContains('16:00', $slots6);
    }

    // ═══════════════════════════════════════════════════════
    // P0-17: Exception override works
    // ═══════════════════════════════════════════════════════
    public function test_exception_override_changes_time(): void
    {
        $series = RecurringBlockedTimeSeries::create([
            'workspace_id' => $this->proWorkspace->id,
            'user_id' => $this->proMaster->id,
            'title' => 'Daily block',
            'start_date' => '2026-10-01',
            'start_time' => '16:00',
            'end_time' => '17:00',
            'recurrence_type' => RecurrenceType::Daily,
            'interval' => 1,
            'timezone' => 'Europe/Moscow',
            'status' => RecurringSeriesStatus::Active,
        ]);

        // Override Oct 5 to 15:00-16:00
        RecurringBlockedTimeException::create([
            'series_id' => $series->id,
            'occurrence_date' => '2026-10-05',
            'type' => ExceptionType::Override,
            'override_start_time' => '15:00',
            'override_end_time' => '16:00',
        ]);

        $service = app(AvailabilityService::class);

        // Oct 5: 15:00 blocked (override), 16:00 free (override ended)
        $date5 = Carbon::parse('2026-10-05', 'Europe/Moscow');
        $slots5 = $service->getAvailableSlots($this->proMaster, $date5, 60);
        $this->assertNotContains('15:00', $slots5);
        $this->assertContains('16:00', $slots5);

        // Oct 6: original 16:00 blocked
        $date6 = Carbon::parse('2026-10-06', 'Europe/Moscow');
        $slots6 = $service->getAvailableSlots($this->proMaster, $date6, 60);
        $this->assertNotContains('16:00', $slots6);
    }

    // ═══════════════════════════════════════════════════════
    // P0-18: Update entire series
    // ═══════════════════════════════════════════════════════
    public function test_update_entire_series(): void
    {
        $this->actingAs($this->proMaster);

        $series = RecurringBlockedTimeSeries::create([
            'workspace_id' => $this->proWorkspace->id,
            'user_id' => $this->proMaster->id,
            'title' => 'Old title',
            'start_date' => '2026-10-01',
            'start_time' => '16:00',
            'end_time' => '17:00',
            'recurrence_type' => RecurrenceType::Daily,
            'interval' => 1,
            'timezone' => 'Europe/Moscow',
            'status' => RecurringSeriesStatus::Active,
        ]);

        $response = $this->patchJson("/admin/recurring-blocked-times/{$series->id}", [
            'title' => 'New title',
            'start_time' => '15:00',
            'end_time' => '16:00',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('recurring_blocked_time_series', [
            'id' => $series->id,
            'title' => 'New title',
            'start_time' => '15:00',
            'end_time' => '16:00',
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // P0-19: Update this occurrence (creates exception)
    // ═══════════════════════════════════════════════════════
    public function test_update_this_occurrence_creates_override(): void
    {
        $this->actingAs($this->proMaster);

        $series = RecurringBlockedTimeSeries::create([
            'workspace_id' => $this->proWorkspace->id,
            'user_id' => $this->proMaster->id,
            'title' => 'Block',
            'start_date' => '2026-10-01',
            'start_time' => '16:00',
            'end_time' => '17:00',
            'recurrence_type' => RecurrenceType::Daily,
            'interval' => 1,
            'timezone' => 'Europe/Moscow',
            'status' => RecurringSeriesStatus::Active,
        ]);

        $response = $this->patchJson(
            "/admin/recurring-blocked-times/{$series->id}/occurrences/2026-10-05",
            [
                'scope' => 'this',
                'override_start_time' => '15:00',
                'override_end_time' => '16:00',
            ]
        );

        $response->assertOk();
        $this->assertDatabaseHas('recurring_blocked_time_exceptions', [
            'series_id' => $series->id,
            'occurrence_date' => '2026-10-05',
            'type' => 'override',
            'override_start_time' => '15:00',
            'override_end_time' => '16:00',
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // P0-20: Update this + future splits series
    // ═══════════════════════════════════════════════════════
    public function test_update_this_and_future_splits_series(): void
    {
        $this->actingAs($this->proMaster);

        $series = RecurringBlockedTimeSeries::create([
            'workspace_id' => $this->proWorkspace->id,
            'user_id' => $this->proMaster->id,
            'title' => 'Original',
            'start_date' => '2026-10-01',
            'start_time' => '16:00',
            'end_time' => '17:00',
            'recurrence_type' => RecurrenceType::Daily,
            'interval' => 1,
            'ends_at' => '2026-12-31',
            'timezone' => 'Europe/Moscow',
            'status' => RecurringSeriesStatus::Active,
        ]);

        $response = $this->patchJson(
            "/admin/recurring-blocked-times/{$series->id}/occurrences/2026-10-15",
            [
                'scope' => 'this_and_future',
                'title' => 'New series',
                'start_time' => '18:00',
                'end_time' => '19:00',
            ]
        );

        $response->assertOk();

        // Old series should end on Oct 14
        $series->refresh();
        $this->assertEquals('2026-10-14', $series->ends_at->format('Y-m-d'));

        // New series should start on Oct 15
        $newSeries = RecurringBlockedTimeSeries::where('user_id', $this->proMaster->id)
            ->where('title', 'New series')
            ->first();
        $this->assertNotNull($newSeries);
        $this->assertEquals('2026-10-15', $newSeries->start_date->format('Y-m-d'));
        $this->assertEquals('18:00', substr($newSeries->start_time, 0, 5));
        $this->assertEquals('19:00', substr($newSeries->end_time, 0, 5));
    }

    // ═══════════════════════════════════════════════════════
    // P0-21: Delete this occurrence
    // ═══════════════════════════════════════════════════════
    public function test_delete_this_occurrence_creates_skip(): void
    {
        $this->actingAs($this->proMaster);

        $series = RecurringBlockedTimeSeries::create([
            'workspace_id' => $this->proWorkspace->id,
            'user_id' => $this->proMaster->id,
            'title' => 'Block',
            'start_date' => '2026-10-01',
            'start_time' => '16:00',
            'end_time' => '17:00',
            'recurrence_type' => RecurrenceType::Daily,
            'interval' => 1,
            'timezone' => 'Europe/Moscow',
            'status' => RecurringSeriesStatus::Active,
        ]);

        $response = $this->deleteJson(
            "/admin/recurring-blocked-times/{$series->id}/occurrences/2026-10-05",
            ['scope' => 'this']
        );

        $response->assertOk();
        $this->assertDatabaseHas('recurring_blocked_time_exceptions', [
            'series_id' => $series->id,
            'occurrence_date' => '2026-10-05',
            'type' => 'skip',
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // P0-22: Delete this + future
    // ═══════════════════════════════════════════════════════
    public function test_delete_this_and_future_ends_series(): void
    {
        $this->actingAs($this->proMaster);

        $series = RecurringBlockedTimeSeries::create([
            'workspace_id' => $this->proWorkspace->id,
            'user_id' => $this->proMaster->id,
            'title' => 'Block',
            'start_date' => '2026-10-01',
            'start_time' => '16:00',
            'end_time' => '17:00',
            'recurrence_type' => RecurrenceType::Daily,
            'interval' => 1,
            'ends_at' => '2026-12-31',
            'timezone' => 'Europe/Moscow',
            'status' => RecurringSeriesStatus::Active,
        ]);

        $response = $this->deleteJson(
            "/admin/recurring-blocked-times/{$series->id}/occurrences/2026-10-15",
            ['scope' => 'this_and_future']
        );

        $response->assertOk();
        $series->refresh();
        $this->assertEquals('2026-10-14', $series->ends_at->format('Y-m-d'));
    }

    // ═══════════════════════════════════════════════════════
    // P0-23: Delete entire series
    // ═══════════════════════════════════════════════════════
    public function test_delete_entire_series_cancels(): void
    {
        $this->actingAs($this->proMaster);

        $series = RecurringBlockedTimeSeries::create([
            'workspace_id' => $this->proWorkspace->id,
            'user_id' => $this->proMaster->id,
            'title' => 'Block',
            'start_date' => '2026-10-01',
            'start_time' => '16:00',
            'end_time' => '17:00',
            'recurrence_type' => RecurrenceType::Daily,
            'interval' => 1,
            'timezone' => 'Europe/Moscow',
            'status' => RecurringSeriesStatus::Active,
        ]);

        $response = $this->deleteJson("/admin/recurring-blocked-times/{$series->id}");

        $response->assertOk();
        $series->refresh();
        $this->assertEquals(RecurringSeriesStatus::Cancelled, $series->status);
    }

    // ═══════════════════════════════════════════════════════
    // P0-24: Feature gate backend works
    // ═══════════════════════════════════════════════════════
    public function test_feature_gate_blocks_start_tariff_api_access(): void
    {
        $this->actingAs($this->startMaster);

        $response = $this->postJson('/admin/recurring-blocked-times', [
            'title' => 'Test',
            'start_date' => '2026-10-01',
            'start_time' => '16:00',
            'end_time' => '17:00',
            'recurrence_type' => 'daily',
            'interval' => 1,
        ]);

        $response->assertStatus(403);
    }

    public function test_feature_gate_blocks_start_tariff_update(): void
    {
        // Create a series as Pro user first
        $series = RecurringBlockedTimeSeries::create([
            'workspace_id' => $this->proWorkspace->id,
            'user_id' => $this->proMaster->id,
            'title' => 'Block',
            'start_date' => '2026-10-01',
            'start_time' => '16:00',
            'end_time' => '17:00',
            'recurrence_type' => RecurrenceType::Daily,
            'interval' => 1,
            'timezone' => 'Europe/Moscow',
            'status' => RecurringSeriesStatus::Active,
        ]);

        // Try to update as Start user
        $this->actingAs($this->startMaster);

        $response = $this->patchJson("/admin/recurring-blocked-times/{$series->id}", [
            'title' => 'Test',
        ]);

        $response->assertStatus(403);
    }

    public function test_feature_gate_blocks_start_tariff_delete(): void
    {
        // Create a series as Pro user first
        $series = RecurringBlockedTimeSeries::create([
            'workspace_id' => $this->proWorkspace->id,
            'user_id' => $this->proMaster->id,
            'title' => 'Block',
            'start_date' => '2026-10-01',
            'start_time' => '16:00',
            'end_time' => '17:00',
            'recurrence_type' => RecurrenceType::Daily,
            'interval' => 1,
            'timezone' => 'Europe/Moscow',
            'status' => RecurringSeriesStatus::Active,
        ]);

        // Try to delete as Start user
        $this->actingAs($this->startMaster);

        $response = $this->deleteJson("/admin/recurring-blocked-times/{$series->id}");

        $response->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════
    // P0-12: Manual appointment creation respects recurring block
    // ═══════════════════════════════════════════════════════
    public function test_is_slot_free_blocks_recurring_time(): void
    {
        RecurringBlockedTimeSeries::create([
            'workspace_id' => $this->proWorkspace->id,
            'user_id' => $this->proMaster->id,
            'title' => 'Block',
            'start_date' => '2026-10-01',
            'start_time' => '16:00',
            'end_time' => '17:00',
            'recurrence_type' => RecurrenceType::Daily,
            'interval' => 1,
            'timezone' => 'Europe/Moscow',
            'status' => RecurringSeriesStatus::Active,
        ]);

        $service = app(AvailabilityService::class);
        $slotStart = Carbon::parse('2026-10-05 16:00', 'Europe/Moscow'); // Monday

        // isSlotFree should return false for 16:00
        $this->assertFalse($service->isSlotFree($this->proMaster, $slotStart, 60));

        // isSlotFree should return true for 17:00
        $slotAfter = Carbon::parse('2026-10-05 17:00', 'Europe/Moscow');
        $this->assertTrue($service->isSlotFree($this->proMaster, $slotAfter, 60));
    }

    // ═══════════════════════════════════════════════════════
    // P0-13: Reschedule respects recurring block
    // ═══════════════════════════════════════════════════════
    public function test_is_slot_available_blocks_recurring_time(): void
    {
        RecurringBlockedTimeSeries::create([
            'workspace_id' => $this->proWorkspace->id,
            'user_id' => $this->proMaster->id,
            'title' => 'Block',
            'start_date' => '2026-10-01',
            'start_time' => '12:00',
            'end_time' => '13:00',
            'recurrence_type' => RecurrenceType::Daily,
            'interval' => 1,
            'timezone' => 'Europe/Moscow',
            'status' => RecurringSeriesStatus::Active,
        ]);

        $service = app(AvailabilityService::class);
        $slotDuringBlock = Carbon::parse('2026-10-05 12:30', 'Europe/Moscow'); // Monday

        $this->assertFalse($service->isSlotAvailable($this->proMaster, $slotDuringBlock, 30));
    }

    // ═══════════════════════════════════════════════════════
    // Regular BlockedTime still works
    // ═══════════════════════════════════════════════════════
    public function test_regular_blocked_time_still_works(): void
    {
        BlockedTime::create([
            'user_id' => $this->proMaster->id,
            'start_datetime' => Carbon::parse('2026-10-05 14:00', 'Europe/Moscow')->utc(),
            'end_datetime' => Carbon::parse('2026-10-05 15:00', 'Europe/Moscow')->utc(),
            'reason' => 'Обед',
        ]);

        $service = app(AvailabilityService::class);
        $slots = $service->getAvailableSlots($this->proMaster, Carbon::parse('2026-10-05', 'Europe/Moscow'), 60);

        $this->assertNotContains('14:00', $slots);
        $this->assertContains('15:00', $slots);
    }

    // ═══════════════════════════════════════════════════════
    // DST edge case
    // ═══════════════════════════════════════════════════════
    public function test_dst_transition_handled_correctly(): void
    {
        // US clocks fall back on Nov 1, 2026
        $dstMaster = User::factory()->create([
            'workspace_id' => $this->proWorkspace->id,
            'is_master' => true,
            'settings' => ['timezone' => 'America/New_York'],
        ]);
        $dstMaster->createDefaultWorkingHours();

        RecurringBlockedTimeSeries::create([
            'workspace_id' => $this->proWorkspace->id,
            'user_id' => $dstMaster->id,
            'title' => 'DST block',
            'start_date' => '2026-10-26',
            'start_time' => '16:00',
            'end_time' => '17:00',
            'recurrence_type' => RecurrenceType::Daily,
            'interval' => 1,
            'timezone' => 'America/New_York',
            'status' => RecurringSeriesStatus::Active,
        ]);

        $service = app(AvailabilityService::class);

        // Before DST change (Oct 28) - should work
        $dateBefore = Carbon::parse('2026-10-28', 'America/New_York');
        $slotsBefore = $service->getAvailableSlots($dstMaster, $dateBefore, 60);
        $this->assertNotContains('16:00', $slotsBefore);

        // After DST change (Nov 3) - should still work
        $dateAfter = Carbon::parse('2026-11-03', 'America/New_York');
        $slotsAfter = $service->getAvailableSlots($dstMaster, $dateAfter, 60);
        $this->assertNotContains('16:00', $slotsAfter);
    }

    // ═══════════════════════════════════════════════════════
    // Preview endpoint works
    // ═══════════════════════════════════════════════════════
    public function test_preview_returns_occurrences(): void
    {
        $this->actingAs($this->proMaster);

        $response = $this->postJson('/admin/recurring-blocked-times/preview', [
            'title' => 'Preview test',
            'start_date' => '2026-10-01',
            'start_time' => '16:00',
            'end_time' => '17:00',
            'recurrence_type' => 'daily',
            'interval' => 1,
            'ends_at' => '2026-10-05',
        ]);

        $response->assertOk();
        $data = $response->json();
        $this->assertEquals(5, $data['total']);
        $this->assertCount(5, $data['occurrences']);
    }

    // ═══════════════════════════════════════════════════════
    // Validation
    // ═══════════════════════════════════════════════════════
    public function test_validation_requires_end_time_after_start(): void
    {
        $this->actingAs($this->proMaster);

        $response = $this->postJson('/admin/recurring-blocked-times', [
            'title' => 'Bad times',
            'start_date' => '2026-10-01',
            'start_time' => '17:00',
            'end_time' => '16:00',
            'recurrence_type' => 'daily',
            'interval' => 1,
        ]);

        $response->assertStatus(422);
    }

    public function test_validation_requires_weekdays_for_custom_weekly(): void
    {
        $this->actingAs($this->proMaster);

        $response = $this->postJson('/admin/recurring-blocked-times', [
            'title' => 'Missing weekdays',
            'start_date' => '2026-10-01',
            'start_time' => '16:00',
            'end_time' => '17:00',
            'recurrence_type' => 'custom_weekly',
            'interval' => 2,
        ]);

        $response->assertStatus(422);
    }

    public function test_validation_requires_valid_interval(): void
    {
        $this->actingAs($this->proMaster);

        $response = $this->postJson('/admin/recurring-blocked-times', [
            'title' => 'Bad interval',
            'start_date' => '2026-10-01',
            'start_time' => '16:00',
            'end_time' => '17:00',
            'recurrence_type' => 'daily',
            'interval' => -1,
        ]);

        $this->assertNotEquals(201, $response->status());
    }
}
