<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentSnapshotInvariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_displays_snapshot_when_legacy_service_deleted(): void
    {
        $appointment = Appointment::factory()->create([
            'service_name' => 'Стрижка',
            'price' => 1500,
            'duration' => 60,
            'service_id' => null,
        ]);

        $this->assertSame('Стрижка', $appointment->display_name);
        $this->assertSame(1500.0, $appointment->display_price);
        $this->assertSame(60, $appointment->display_duration);
    }

    public function test_fallback_when_no_snapshot_and_no_fk(): void
    {
        $appointment = Appointment::factory()->create([
            'service_name' => null,
            'price' => null,
            'duration' => null,
            'service_id' => null,
        ]);

        $this->assertSame('Услуга удалена', $appointment->display_name);
        $this->assertSame(0.0, $appointment->display_price);
        $this->assertSame(0, $appointment->display_duration);
    }

    public function test_snapshot_takes_priority_over_master_service(): void
    {
        $master = User::factory()->master()->create();
        $workspace = Workspace::create(['name' => 'Test WS', 'owner_id' => $master->id]);
        $master->update(['workspace_id' => $workspace->id]);

        $catalog = ServiceCatalog::create([
            'workspace_id' => $workspace->id,
            'title' => 'СТАРОЕ ИМЯ',
            'base_price' => 999,
            'base_duration' => 15,
        ]);
        $ms = MasterService::create([
            'master_id' => $master->id,
            'catalog_id' => $catalog->id,
            'is_active' => true,
        ]);

        $appointment = Appointment::factory()->create([
            'master_id' => $master->id,
            'master_service_id' => $ms->id,
            'service_name' => 'Стрижка',
            'price' => 1500,
            'duration' => 60,
        ]);

        // Снапшот 'Стрижка' приоритетнее catalog.title 'СТАРОЕ ИМЯ'
        $this->assertSame('Стрижка', $appointment->display_name);
        $this->assertSame(1500.0, $appointment->display_price);
        $this->assertSame(60, $appointment->display_duration);
    }

    public function test_to_calendar_array_uses_snapshot(): void
    {
        $appointment = Appointment::factory()->create([
            'service_name' => 'Маникюр',
            'price' => 2000,
            'duration' => 90,
            'service_id' => null,
        ]);

        $calendar = $appointment->toCalendarArray();

        $this->assertSame('Маникюр', $calendar['service']);
        $this->assertSame(90, $calendar['duration']);
        $this->assertSame(2000.0, $calendar['price']);
    }
}
