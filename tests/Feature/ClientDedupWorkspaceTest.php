<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkingHour;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClientDedupWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private static int $wsCounter = 0;

    private function createWorkspace(): Workspace
    {
        self::$wsCounter++;

        return Workspace::create([
            'name' => 'Studio '.self::$wsCounter,
            'owner_id' => User::factory()->create()->id,
        ]);
    }

    private function createMasterInWorkspace(Workspace $workspace): User
    {
        return User::factory()->master()->create([
            'workspace_id' => $workspace->id,
            'role' => 'owner',
        ]);
    }

    public function test_no_client_copy_on_cross_master_booking(): void
    {
        $workspace = $this->createWorkspace();
        $masterA = $this->createMasterInWorkspace($workspace);
        $masterB = User::factory()->master()->create([
            'workspace_id' => $workspace->id,
            'role' => 'master',
        ]);

        $client = Client::create([
            'user_id' => $masterA->id,
            'workspace_id' => $workspace->id,
            'is_personal' => false,
            'phone' => '+79001112233',
            'name' => 'Общий клиент',
        ]);

        $catalog = ServiceCatalog::create([
            'workspace_id' => $workspace->id,
            'title' => 'Стрижка',
            'base_price' => 1000.00,
            'base_duration' => 60,
        ]);
        $service = MasterService::create([
            'master_id' => $masterB->id,
            'catalog_id' => $catalog->id,
            'price_override' => 1000.00,
            'duration_override' => 60,
            'is_active' => true,
        ]);

        $dayOfWeek = Carbon::tomorrow('Europe/Moscow')->dayOfWeek;
        WorkingHour::updateOrCreate(
            ['user_id' => $masterB->id, 'day_of_week' => $dayOfWeek],
            [
                'start_time' => '09:00',
                'end_time' => '18:00',
                'break_start_time' => '13:00',
                'break_end_time' => '14:00',
                'is_working' => true,
            ],
        );

        $response = $this->actingAs($masterA, 'web')
            ->postJson(route('admin.calendar.store'), [
                'client_id' => $client->id,
                'service_id' => $service->id,
                'date' => now()->addDay()->format('Y-m-d'),
                'time' => '10:00',
            ]);

        $response->assertStatus(302);

        $this->assertDatabaseHas('appointments', [
            'client_id' => $client->id,
            'master_service_id' => $service->id,
        ]);

        $this->assertSame(1, Client::where('phone', '+79001112233')->count());
    }

    public function test_workspace_phone_unique_blocks_duplicate(): void
    {
        $workspace = $this->createWorkspace();
        $master = $this->createMasterInWorkspace($workspace);

        Client::create([
            'user_id' => $master->id,
            'workspace_id' => $workspace->id,
            'is_personal' => false,
            'phone' => '+79001112233',
            'name' => 'Первый',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Client::create([
            'user_id' => $master->id,
            'workspace_id' => $workspace->id,
            'is_personal' => false,
            'phone' => '+79001112233',
            'name' => 'Дубль',
        ]);
    }

    public function test_legacy_null_workspace_not_affected_by_partial_index(): void
    {
        $masterA = User::factory()->master()->create([
            'workspace_id' => null,
            'role' => 'owner',
        ]);
        $masterB = User::factory()->master()->create([
            'workspace_id' => null,
            'role' => 'owner',
        ]);

        $clientA = Client::create([
            'user_id' => $masterA->id,
            'workspace_id' => null,
            'is_personal' => true,
            'phone' => '+79001112233',
            'name' => 'Клиент А',
        ]);

        $clientB = Client::create([
            'user_id' => $masterB->id,
            'workspace_id' => null,
            'is_personal' => true,
            'phone' => '+79001112233',
            'name' => 'Клиент B',
        ]);

        $this->assertNotSame($clientA->id, $clientB->id);
        $this->assertSame(2, Client::where('phone', '+79001112233')->count());
    }

    public function test_solo_booking_regression(): void
    {
        $master = User::factory()->master()->create([
            'workspace_id' => null,
            'role' => 'owner',
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);

        $client = Client::create([
            'user_id' => $master->id,
            'workspace_id' => null,
            'is_personal' => true,
            'phone' => '+79001112233',
            'name' => 'Solo клиент',
        ]);

        $workspace = Workspace::create(['name' => 'Solo WS', 'owner_id' => $master->id]);
        $master->update(['workspace_id' => $workspace->id]);

        $catalog = ServiceCatalog::create([
            'workspace_id' => $workspace->id,
            'title' => 'Маникюр',
            'base_price' => 500.00,
            'base_duration' => 30,
        ]);
        $service = MasterService::create([
            'master_id' => $master->id,
            'catalog_id' => $catalog->id,
            'price_override' => 500.00,
            'duration_override' => 30,
            'is_active' => true,
        ]);

        $dayOfWeek = Carbon::tomorrow('Europe/Moscow')->dayOfWeek;
        WorkingHour::updateOrCreate(
            ['user_id' => $master->id, 'day_of_week' => $dayOfWeek],
            [
                'start_time' => '09:00',
                'end_time' => '18:00',
                'break_start_time' => '13:00',
                'break_end_time' => '14:00',
                'is_working' => true,
            ],
        );

        $response = $this->actingAs($master, 'web')
            ->postJson(route('admin.calendar.store'), [
                'client_id' => $client->id,
                'service_id' => $service->id,
                'date' => now()->addDay()->format('Y-m-d'),
                'time' => '10:00',
            ]);
        $response->assertStatus(302);

        $this->assertDatabaseHas('appointments', [
            'client_id' => $client->id,
            'master_service_id' => $service->id,
        ]);

        $this->assertSame(1, Client::where('phone', '+79001112233')->count());
    }
}
