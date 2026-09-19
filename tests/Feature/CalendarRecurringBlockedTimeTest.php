<?php

namespace Tests\Feature;

use App\Enums\ExceptionType;
use App\Enums\RecurrenceType;
use App\Enums\RecurringSeriesStatus;
use App\Models\RecurringBlockedTimeException;
use App\Models\RecurringBlockedTimeSeries;
use App\Models\Subscription;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CalendarRecurringBlockedTimeTest extends TestCase
{
    use RefreshDatabase;

    private User $master;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->master = User::factory()->master()->create([
            'settings' => ['timezone' => 'Europe/Moscow'],
        ]);
        $this->workspace = Workspace::create([
            'name' => 'Test Studio',
            'owner_id' => $this->master->id,
        ]);
        $this->master->update(['workspace_id' => $this->workspace->id]);
        $this->master->createDefaultWorkingHours();
    }

    // ─────────────────────────────────────────────────
    // A: Recurring occurrence appears in calendar response
    // ─────────────────────────────────────────────────
    public function test_recurring_occurrence_appears_in_calendar_data(): void
    {
        RecurringBlockedTimeSeries::create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->master->id,
            'title' => 'Lunch block',
            'start_date' => '2026-10-01',
            'start_time' => '12:00',
            'end_time' => '13:00',
            'recurrence_type' => RecurrenceType::Daily,
            'interval' => 1,
            'timezone' => 'Europe/Moscow',
            'status' => RecurringSeriesStatus::Active,
        ]);

        $this->actingAs($this->master);

        $response = $this->getJson('/admin/calendar/data?start=2026-10-05&end=2026-10-05');

        $response->assertOk();

        $blocked = collect($response->json('blockedTimes'));
        $recurring = $blocked->where('is_recurring', true);

        $this->assertTrue($recurring->isNotEmpty(), 'No recurring blocked times found');
        $this->assertEquals('12:00', $recurring->first()['start_time']);
        $this->assertEquals('13:00', $recurring->first()['end_time']);
    }

    // ─────────────────────────────────────────────────
    // B: is_recurring and series_id are returned
    // ─────────────────────────────────────────────────
    public function test_recurring_occurrence_has_is_recurring_and_series_id(): void
    {
        $series = RecurringBlockedTimeSeries::create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->master->id,
            'title' => 'Block',
            'start_date' => '2026-10-01',
            'start_time' => '16:00',
            'end_time' => '17:00',
            'recurrence_type' => RecurrenceType::Daily,
            'interval' => 1,
            'timezone' => 'Europe/Moscow',
            'status' => RecurringSeriesStatus::Active,
        ]);

        $this->actingAs($this->master);

        $response = $this->getJson('/admin/calendar/data?start=2026-10-05&end=2026-10-05');

        $response->assertOk();

        $blocked = collect($response->json('blockedTimes'));
        $recurring = $blocked->where('is_recurring', true)->first();

        $this->assertNotNull($recurring);
        $this->assertTrue($recurring['is_recurring']);
        $this->assertEquals($series->id, $recurring['series_id']);
        $this->assertStringStartsWith('recurring:', $recurring['id']);
    }

    // ─────────────────────────────────────────────────
    // C: Skip exception — occurrence absent
    // ─────────────────────────────────────────────────
    public function test_skip_exception_excludes_occurrence(): void
    {
        $series = RecurringBlockedTimeSeries::create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->master->id,
            'title' => 'Daily block',
            'start_date' => '2026-10-01',
            'start_time' => '16:00',
            'end_time' => '17:00',
            'recurrence_type' => RecurrenceType::Daily,
            'interval' => 1,
            'timezone' => 'Europe/Moscow',
            'status' => RecurringSeriesStatus::Active,
        ]);

        RecurringBlockedTimeException::create([
            'series_id' => $series->id,
            'occurrence_date' => '2026-10-05',
            'type' => ExceptionType::Skip,
        ]);

        $this->actingAs($this->master);

        $response = $this->getJson('/admin/calendar/data?start=2026-10-05&end=2026-10-06');

        $response->assertOk();

        $blocked = collect($response->json('blockedTimes'));
        $recurring = $blocked->where('is_recurring', true);

        // Oct 5 should NOT appear (skipped)
        $this->assertFalse(
            $recurring->contains('date', '2026-10-05'),
            'Skipped occurrence should not appear',
        );

        // Oct 6 should still appear
        $this->assertTrue(
            $recurring->contains('date', '2026-10-06'),
            'Non-skipped occurrence should appear',
        );
    }

    // ─────────────────────────────────────────────────
    // D: Override exception — original replaced with override
    // ─────────────────────────────────────────────────
    public function test_override_exception_replaces_occurrence_time(): void
    {
        $series = RecurringBlockedTimeSeries::create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->master->id,
            'title' => 'Daily block',
            'start_date' => '2026-10-01',
            'start_time' => '16:00',
            'end_time' => '17:00',
            'recurrence_type' => RecurrenceType::Daily,
            'interval' => 1,
            'timezone' => 'Europe/Moscow',
            'status' => RecurringSeriesStatus::Active,
        ]);

        RecurringBlockedTimeException::create([
            'series_id' => $series->id,
            'occurrence_date' => '2026-10-05',
            'type' => ExceptionType::Override,
            'override_start_time' => '15:00',
            'override_end_time' => '16:00',
        ]);

        $this->actingAs($this->master);

        $response = $this->getJson('/admin/calendar/data?start=2026-10-05&end=2026-10-06');

        $response->assertOk();

        $blocked = collect($response->json('blockedTimes'));
        $recurring = $blocked->where('is_recurring', true);

        // Oct 5 should have override time (15:00-16:00), not original (16:00-17:00)
        $oct5 = $recurring->where('date', '2026-10-05')->values();
        $this->assertCount(1, $oct5, 'Should have exactly one occurrence for Oct 5');
        $this->assertEquals('15:00', $oct5[0]['start_time']);
        $this->assertEquals('16:00', $oct5[0]['end_time']);

        // Oct 6 should have original time (16:00-17:00)
        $oct6 = $recurring->where('date', '2026-10-06')->values();
        $this->assertCount(1, $oct6);
        $this->assertEquals('16:00', $oct6[0]['start_time']);
        $this->assertEquals('17:00', $oct6[0]['end_time']);
    }

    // ─────────────────────────────────────────────────
    // E: Out-of-range occurrences excluded
    // ─────────────────────────────────────────────────
    public function test_occurrences_outside_range_excluded(): void
    {
        RecurringBlockedTimeSeries::create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->master->id,
            'title' => 'Daily block',
            'start_date' => '2026-10-01',
            'start_time' => '16:00',
            'end_time' => '17:00',
            'recurrence_type' => RecurrenceType::Daily,
            'interval' => 1,
            'timezone' => 'Europe/Moscow',
            'status' => RecurringSeriesStatus::Active,
        ]);

        $this->actingAs($this->master);

        // Request only Oct 5
        $response = $this->getJson('/admin/calendar/data?start=2026-10-05&end=2026-10-05');

        $response->assertOk();

        $blocked = collect($response->json('blockedTimes'));
        $recurring = $blocked->where('is_recurring', true);

        // Should only contain Oct 5, not Oct 4 or Oct 6
        $this->assertTrue($recurring->contains('date', '2026-10-05'));
        $this->assertFalse($recurring->contains('date', '2026-10-04'));
        $this->assertFalse($recurring->contains('date', '2026-10-06'));
    }

    // ─────────────────────────────────────────────────
    // F: Timezone is correct
    // ─────────────────────────────────────────────────
    public function test_recurring_occurrence_uses_master_timezone(): void
    {
        RecurringBlockedTimeSeries::create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->master->id,
            'title' => 'Moscow block',
            'start_date' => '2026-10-01',
            'start_time' => '16:00',
            'end_time' => '17:00',
            'recurrence_type' => RecurrenceType::Daily,
            'interval' => 1,
            'timezone' => 'Europe/Moscow',
            'status' => RecurringSeriesStatus::Active,
        ]);

        $this->actingAs($this->master);

        $response = $this->getJson('/admin/calendar/data?start=2026-10-05&end=2026-10-05');

        $response->assertOk();

        $blocked = collect($response->json('blockedTimes'));
        $recurring = $blocked->where('is_recurring', true)->first();

        $this->assertNotNull($recurring);
        // Times should be in Moscow time (16:00-17:00), not UTC
        $this->assertEquals('16:00', $recurring['start_time']);
        $this->assertEquals('17:00', $recurring['end_time']);
        $this->assertEquals('2026-10-05', $recurring['date']);
    }
}
