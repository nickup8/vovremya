<?php

namespace Tests\Feature;

use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CreateServiceAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateServiceActionTest extends TestCase
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

    public function test_creates_catalog_and_master_service(): void
    {
        $master = $this->createMasterWithWorkspace();

        $ms = app(CreateServiceAction::class)->execute($master, [
            'title' => 'Стрижка',
            'price' => 1500,
            'duration_minutes' => 60,
        ]);

        $this->assertInstanceOf(MasterService::class, $ms);
        $this->assertSame(1, ServiceCatalog::count());
        $this->assertSame(1, MasterService::count());

        $catalog = ServiceCatalog::first();
        $this->assertSame($master->workspace_id, $catalog->workspace_id);
        $this->assertSame('Стрижка', $catalog->title);
        $this->assertSame('1500.00', $catalog->base_price);
        $this->assertSame(60, $catalog->base_duration);

        $this->assertSame($master->id, $ms->master_id);
        $this->assertSame($catalog->id, $ms->catalog_id);
        $this->assertNull($ms->price_override);
    }

    public function test_catalog_idempotent_same_title(): void
    {
        $master = $this->createMasterWithWorkspace();
        $action = app(CreateServiceAction::class);
        $data = ['title' => 'Стрижка', 'price' => 1500, 'duration_minutes' => 60];

        $action->execute($master, $data);
        $action->execute($master, $data);

        $this->assertSame(1, ServiceCatalog::count());
        $this->assertSame(1, MasterService::count());
    }

    public function test_two_masters_same_workspace_same_title(): void
    {
        $master1 = $this->createMasterWithWorkspace();
        $master2 = User::factory()->master()->create([
            'workspace_id' => $master1->workspace_id,
        ]);

        $action = app(CreateServiceAction::class);
        $data = ['title' => 'Маникюр', 'price' => 2000, 'duration_minutes' => 90];

        $action->execute($master1, $data);
        $action->execute($master2, $data);

        $this->assertSame(1, ServiceCatalog::count());
        $this->assertSame(2, MasterService::count());
        $this->assertSame(2, ServiceCatalog::first()->masterServices()->count());
    }

    public function test_price_override_is_null_inherits_base(): void
    {
        $master = $this->createMasterWithWorkspace();

        $ms = app(CreateServiceAction::class)->execute($master, [
            'title' => 'Педикюр',
            'price' => 2000,
            'duration_minutes' => 45,
        ]);

        $catalog = ServiceCatalog::first();

        $this->assertNull($ms->price_override);
        $this->assertSame('2000.00', $catalog->base_price);
        $this->assertSame('2000.00', $ms->price_override ?? $catalog->base_price);
    }

    public function test_atomic_rollback_on_failure(): void
    {
        $master = $this->createMasterWithWorkspace();

        \Illuminate\Support\Facades\DB::shouldReceive('transaction')
            ->once()
            ->andThrow(new \RuntimeException('Simulated DB failure'));

        try {
            app(CreateServiceAction::class)->execute($master, [
                'title' => 'New service',
                'price' => 500,
                'duration_minutes' => 30,
            ]);
        } catch (\RuntimeException) {
            // ожидаемо
        }

        $this->assertSame(0, ServiceCatalog::count());
        $this->assertSame(0, MasterService::count());
    }

    public function test_throws_when_workspace_missing(): void
    {
        $master = User::factory()->master()->create(['workspace_id' => null]);

        $this->expectException(\RuntimeException::class);

        app(CreateServiceAction::class)->execute($master, [
            'title' => 'Test',
            'price' => 100,
            'duration_minutes' => 30,
        ]);
    }
}
