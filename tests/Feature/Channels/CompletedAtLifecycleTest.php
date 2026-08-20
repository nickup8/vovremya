<?php

namespace Tests\Feature\Channels;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AppointmentStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompletedAtLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    private MasterService $service;

    private AppointmentStatusService $statusService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->master = User::factory()->master()->create();
        $workspace = Workspace::create(['name' => 'WS', 'owner_id' => $this->master->id]);
        $catalog = ServiceCatalog::create(['workspace_id' => $workspace->id, 'title' => 'Т', 'base_price' => 1000, 'base_duration' => 60]);
        $this->service = MasterService::create(['master_id' => $this->master->id, 'catalog_id' => $catalog->id, 'is_active' => true]);
        $this->statusService = app(AppointmentStatusService::class);
    }

    private function make(AppointmentStatus $status): Appointment
    {
        return Appointment::factory()
            ->forMaster($this->master)
            ->withMasterService($this->service)
            ->create(['status' => $status, 'start_time' => now()->subDays(1)]);
    }

    public function test_booked_to_paid_sets_completed_at(): void
    {
        $a = $this->make(AppointmentStatus::Booked);
        $this->statusService->transition($a, AppointmentStatus::Paid);
        $this->assertNotNull($a->fresh()->completed_at);
    }

    public function test_prepaid_to_paid_sets_completed_at(): void
    {
        $a = $this->make(AppointmentStatus::Prepaid);
        $this->statusService->transition($a, AppointmentStatus::Paid);
        $this->assertNotNull($a->fresh()->completed_at);
    }

    public function test_no_show_to_paid_sets_completed_at(): void
    {
        $a = $this->make(AppointmentStatus::NoShow);
        $this->statusService->transition($a, AppointmentStatus::Paid);
        $this->assertNotNull($a->fresh()->completed_at);
    }

    public function test_paid_to_no_show_clears_completed_at(): void
    {
        $a = $this->make(AppointmentStatus::Booked);
        $this->statusService->transition($a, AppointmentStatus::Paid);
        $this->assertNotNull($a->fresh()->completed_at);

        $this->statusService->transition($a->fresh(), AppointmentStatus::NoShow);
        $this->assertNull($a->fresh()->completed_at);
    }

    public function test_repeated_paid_records_new_completed_at(): void
    {
        $a = $this->make(AppointmentStatus::Booked);

        $this->travelTo(now()->setDate(2026, 8, 31)->setTime(12, 0));
        $this->statusService->transition($a, AppointmentStatus::Paid);
        $firstCompleted = $a->fresh()->completed_at;

        $this->travelTo(now()->setDate(2026, 9, 1)->setTime(12, 0));
        $this->statusService->transition($a->fresh(), AppointmentStatus::NoShow);

        $this->travelTo(now()->setDate(2026, 9, 2)->setTime(12, 0));
        $this->statusService->transition($a->fresh(), AppointmentStatus::Paid);
        $secondCompleted = $a->fresh()->completed_at;

        $this->assertNotNull($secondCompleted);
        $this->assertSame('2026-09-02', $secondCompleted->toDateString());
        $this->assertTrue($secondCompleted->gt($firstCompleted));

        $this->travelBack();
    }

    public function test_non_paid_transitions_do_not_set_completed_at(): void
    {
        $a = $this->make(AppointmentStatus::Booked);
        $this->statusService->transition($a, AppointmentStatus::Cancelled);
        $this->assertNull($a->fresh()->completed_at);
    }

    public function test_old_paid_with_null_completed_at_does_not_break_queries(): void
    {
        // Историческая paid-запись без completed_at (без backfill).
        $a = $this->make(AppointmentStatus::Paid);
        $a->forceFill(['completed_at' => null])->save();

        $count = Appointment::where('master_id', $this->master->id)
            ->where('status', AppointmentStatus::Paid)
            ->whereNotNull('completed_at')
            ->count();

        $this->assertSame(0, $count);
        $this->assertSame(AppointmentStatus::Paid, $a->fresh()->status);
    }
}
