<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackfillSoloServicesTest extends TestCase
{
    use RefreshDatabase;

    private function createSoloWorkspace(): array
    {
        $master = User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'Solo WS ' . Str::random(6),
            'owner_id' => $master->id,
        ]);
        $master->update(['workspace_id' => $workspace->id]);

        return [$workspace, $master];
    }

    public function test_dry_run_does_not_change_anything(): void
    {
        [$ws, $master] = $this->createSoloWorkspace();

        ServiceCatalog::create([
            'workspace_id' => $ws->id,
            'title' => 'Orphan',
            'base_price' => 1000,
            'base_duration' => 30,
            'is_active' => true,
        ]);

        $this->artisan('services:backfill-solo --dry-run')->assertExitCode(0);

        $this->assertSame(0, MasterService::count());
    }

    public function test_orphan_active_catalog_gets_ms(): void
    {
        [$ws, $master] = $this->createSoloWorkspace();

        $catalog = ServiceCatalog::create([
            'workspace_id' => $ws->id,
            'title' => 'Orphan Service',
            'base_price' => 1500,
            'base_duration' => 45,
            'is_active' => true,
        ]);

        $this->artisan('services:backfill-solo')->assertExitCode(0);

        $this->assertSame(1, MasterService::count());
        $ms = MasterService::first();
        $this->assertSame($master->id, $ms->master_id);
        $this->assertSame($catalog->id, $ms->catalog_id);
        $this->assertTrue($ms->is_active);
        $this->assertNull($ms->price_override);
        $this->assertNull($ms->duration_override);
    }

    public function test_inactive_ms_normalized_to_true(): void
    {
        [$ws, $master] = $this->createSoloWorkspace();

        $catalog = ServiceCatalog::create([
            'workspace_id' => $ws->id,
            'title' => 'Service',
            'base_price' => 1000,
            'base_duration' => 30,
            'is_active' => true,
        ]);

        $ms = MasterService::create([
            'master_id' => $master->id,
            'catalog_id' => $catalog->id,
            'is_active' => false,
        ]);

        $this->artisan('services:backfill-solo')->assertExitCode(0);

        $ms->refresh();
        $this->assertTrue($ms->is_active);
    }

    public function test_idempotent_no_duplicates(): void
    {
        [$ws, $master] = $this->createSoloWorkspace();

        ServiceCatalog::create([
            'workspace_id' => $ws->id,
            'title' => 'Service',
            'base_price' => 1000,
            'base_duration' => 30,
            'is_active' => true,
        ]);

        $this->artisan('services:backfill-solo')->assertExitCode(0);
        $this->assertSame(1, MasterService::count());

        $this->artisan('services:backfill-solo')->assertExitCode(0);
        $this->assertSame(1, MasterService::count());
    }

    public function test_workspace_with_multiple_masters_skips(): void
    {
        $master1 = User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'Multi WS ' . Str::random(6),
            'owner_id' => $master1->id,
        ]);
        $master1->update(['workspace_id' => $workspace->id]);
        User::factory()->master()->create(['workspace_id' => $workspace->id]);

        ServiceCatalog::create([
            'workspace_id' => $workspace->id,
            'title' => 'Orphan',
            'base_price' => 1000,
            'base_duration' => 30,
            'is_active' => true,
        ]);

        $this->artisan('services:backfill-solo')->assertExitCode(0);

        $this->assertSame(0, MasterService::count());
    }

    public function test_appointment_rows_unchanged(): void
    {
        [$ws, $master] = $this->createSoloWorkspace();

        $catalog = ServiceCatalog::create([
            'workspace_id' => $ws->id,
            'title' => 'Service',
            'base_price' => 1000,
            'base_duration' => 30,
            'is_active' => true,
        ]);

        $appointment = Appointment::factory()->forMaster($master)->create([
            'master_service_id' => null,
            'service_name' => 'Legacy Service',
            'price' => 500,
            'duration' => 30,
        ]);

        $this->artisan('services:backfill-solo')->assertExitCode(0);

        $appointment->refresh();
        $this->assertNull($appointment->master_service_id);
        $this->assertSame('Legacy Service', $appointment->service_name);
        $this->assertSame(500.0, (float) $appointment->price);
    }
}
