<?php

namespace Tests\Feature;

use App\Models\MasterService;
use App\Models\Service;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillServiceCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function createMasterWithWorkspace(): User
    {
        $master = User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'Test Studio',
            'owner_id' => $master->id,
        ]);
        $master->update(['workspace_id' => $workspace->id]);

        return $master;
    }

    public function test_backfill_creates_catalog_and_master_for_legacy(): void
    {
        $master = $this->createMasterWithWorkspace();

        Service::create([
            'user_id' => $master->id,
            'title' => 'Стрижка',
            'price' => 1500,
            'duration_minutes' => 30,
        ]);

        $master2 = User::factory()->master()->create([
            'workspace_id' => $master->workspace_id,
        ]);

        Service::create([
            'user_id' => $master2->id,
            'title' => 'Маникюр',
            'price' => 2000,
            'duration_minutes' => 60,
        ]);

        $this->artisan('services:backfill-catalog')
            ->assertExitCode(0);

        $this->assertSame(2, ServiceCatalog::count());
        $this->assertSame(2, MasterService::count());

        $catalog1 = ServiceCatalog::where('title', 'Стрижка')->first();
        $this->assertSame('1500.00', $catalog1->base_price);
        $this->assertSame(30, $catalog1->base_duration);

        $catalog2 = ServiceCatalog::where('title', 'Маникюр')->first();
        $this->assertSame('2000.00', $catalog2->base_price);
        $this->assertSame(60, $catalog2->base_duration);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $master = $this->createMasterWithWorkspace();

        Service::create([
            'user_id' => $master->id,
            'title' => 'Стрижка',
            'price' => 1500,
            'duration_minutes' => 30,
        ]);

        $this->artisan('services:backfill-catalog', ['--dry-run' => true])
            ->expectsOutputToContain('WOULD SYNC')
            ->assertExitCode(0);

        $this->assertSame(0, ServiceCatalog::count());
        $this->assertSame(0, MasterService::count());
    }

    public function test_backfill_idempotent_second_run(): void
    {
        $master = $this->createMasterWithWorkspace();

        Service::create([
            'user_id' => $master->id,
            'title' => 'Стрижка',
            'price' => 1500,
            'duration_minutes' => 30,
        ]);

        $this->artisan('services:backfill-catalog')->assertExitCode(0);
        $this->artisan('services:backfill-catalog')->assertExitCode(0);

        $this->assertSame(1, ServiceCatalog::count());
        $this->assertSame(1, MasterService::count());
    }

    public function test_two_masters_same_workspace_same_title_one_catalog(): void
    {
        $master1 = $this->createMasterWithWorkspace();
        $master2 = User::factory()->master()->create([
            'workspace_id' => $master1->workspace_id,
        ]);

        Service::create([
            'user_id' => $master1->id,
            'title' => 'Стрижка',
            'price' => 1500,
            'duration_minutes' => 30,
        ]);

        Service::create([
            'user_id' => $master2->id,
            'title' => 'Стрижка',
            'price' => 1500,
            'duration_minutes' => 30,
        ]);

        $this->artisan('services:backfill-catalog')->assertExitCode(0);

        $this->assertSame(1, ServiceCatalog::count());
        $this->assertSame(2, MasterService::count());
        $this->assertSame(2, ServiceCatalog::first()->masterServices()->count());
    }

    public function test_skip_service_without_workspace(): void
    {
        $master = User::factory()->master()->create();
        // workspace_id = null

        Service::create([
            'user_id' => $master->id,
            'title' => 'Стрижка',
            'price' => 1500,
            'duration_minutes' => 30,
        ]);

        $this->artisan('services:backfill-catalog')
            ->expectsOutputToContain('SKIP')
            ->assertExitCode(0);

        $this->assertSame(0, ServiceCatalog::count());
        $this->assertSame(0, MasterService::count());
    }
}
