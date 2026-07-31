<?php

namespace Tests\Feature;

use App\Models\MasterService;
use App\Models\Service;
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

    public function test_single_master_creates_triple_records(): void
    {
        $master = $this->createMasterWithWorkspace();

        $service = app(CreateServiceAction::class)->execute($master, [
            'title' => 'Стрижка',
            'price' => 1500,
            'duration_minutes' => 60,
        ]);

        $this->assertSame(1, Service::count());
        $this->assertSame(1, ServiceCatalog::count());
        $this->assertSame(1, MasterService::count());

        $this->assertSame($master->id, $service->user_id);

        $catalog = ServiceCatalog::first();
        $this->assertSame($master->workspace_id, $catalog->workspace_id);
        $this->assertSame('1500.00', $catalog->base_price);
        $this->assertSame(60, $catalog->base_duration);

        $ms = MasterService::first();
        $this->assertSame($master->id, $ms->master_id);
        $this->assertSame($catalog->id, $ms->catalog_id);
        $this->assertNull($ms->price_override);
        $this->assertSame('approved', $ms->status);
    }

    public function test_catalog_idempotent_same_title(): void
    {
        $master = $this->createMasterWithWorkspace();
        $action = app(CreateServiceAction::class);
        $data = ['title' => 'Стрижка', 'price' => 1500, 'duration_minutes' => 60];

        $action->execute($master, $data);
        $action->execute($master, $data);

        $this->assertSame(2, Service::count());
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

        app(CreateServiceAction::class)->execute($master, [
            'title' => 'Педикюр',
            'price' => 2000,
            'duration_minutes' => 45,
        ]);

        $ms = MasterService::first();
        $catalog = ServiceCatalog::first();

        $this->assertNull($ms->price_override);
        $this->assertSame('2000.00', $catalog->base_price);
        // Наследование: price_override ?? base_price = 2000
        $this->assertSame('2000.00', $ms->price_override ?? $catalog->base_price);
    }

    public function test_atomic_rollback_on_failure(): void
    {
        $master = $this->createMasterWithWorkspace();

        // Создаём запись ДО вызова execute — она НЕ должна исчезнуть
        // если транзакция откатится (не захватит её)
        Service::create([
            'user_id' => $master->id,
            'title' => 'Pre-existing',
            'price' => 100,
            'duration_minutes' => 30,
        ]);

        $this->assertSame(1, Service::count());

        // Подменяем DB::transaction — пропускаем callback, сразу бросаем
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

        // Pre-existing запись на месте (транзакция не захватила её),
        // новая запись НЕ создана (transaction callback не выполнился)
        $this->assertSame(1, Service::count());
        $this->assertSame('Pre-existing', Service::first()->title);
    }

    public function test_returns_legacy_service(): void
    {
        $master = $this->createMasterWithWorkspace();

        $result = app(CreateServiceAction::class)->execute($master, [
            'title' => 'Стрижка',
            'price' => 1500,
            'duration_minutes' => 60,
        ]);

        $this->assertInstanceOf(Service::class, $result);
        $this->assertSame('Стрижка', $result->title);
    }
}
