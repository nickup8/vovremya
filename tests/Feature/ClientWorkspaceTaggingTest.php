<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClientWorkspaceTaggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_is_personal_true(): void
    {
        $master = User::factory()->master()->create([
            'workspace_id' => null,
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);

        $client = Client::create([
            'user_id' => $master->id,
            'phone' => '+79001112233',
            'name' => 'Иван',
        ]);

        $this->assertTrue($client->is_personal);
        $this->assertNull($client->workspace_id);
    }

    public function test_backfill_sets_workspace_id_for_studio_master(): void
    {
        $workspace = Workspace::create([
            'name' => 'Studio',
            'owner_id' => User::factory()->create()->id,
        ]);

        $master = User::factory()->master()->create([
            'workspace_id' => $workspace->id,
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);

        $client = Client::create([
            'user_id' => $master->id,
            'phone' => '+79001112233',
            'name' => 'Мария',
        ]);

        $this->assertNull($client->workspace_id);

        DB::statement('
            UPDATE clients SET workspace_id = (
                SELECT u.workspace_id FROM users u WHERE u.id = clients.user_id
            ) WHERE EXISTS (
                SELECT 1 FROM users u WHERE u.id = clients.user_id AND u.workspace_id IS NOT NULL
            )
        ');

        $client->refresh();

        $this->assertSame($workspace->id, $client->workspace_id);
    }

    public function test_solo_client_workspace_null_after_backfill(): void
    {
        $master = User::factory()->master()->create([
            'workspace_id' => null,
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);

        $client = Client::create([
            'user_id' => $master->id,
            'phone' => '+79001112233',
            'name' => 'Пётр',
        ]);

        DB::statement('
            UPDATE clients SET workspace_id = (
                SELECT u.workspace_id FROM users u WHERE u.id = clients.user_id
            ) WHERE EXISTS (
                SELECT 1 FROM users u WHERE u.id = clients.user_id AND u.workspace_id IS NOT NULL
            )
        ');

        $client->refresh();

        $this->assertNull($client->workspace_id);
        $this->assertTrue($client->is_personal);
    }

    public function test_workspace_relationship(): void
    {
        $workspace = Workspace::create([
            'name' => 'Studio',
            'owner_id' => User::factory()->create()->id,
        ]);

        $master = User::factory()->master()->create([
            'workspace_id' => $workspace->id,
        ]);

        $client = Client::create([
            'user_id' => $master->id,
            'workspace_id' => $workspace->id,
            'is_personal' => false,
            'phone' => '+79001112233',
            'name' => 'Анна',
        ]);

        $this->assertNotNull($client->workspace);
        $this->assertSame($workspace->id, $client->workspace->id);
        $this->assertSame('Studio', $client->workspace->name);
    }

    public function test_client_creation_regression(): void
    {
        $master = User::factory()->master()->create([
            'workspace_id' => null,
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);

        $client = Client::firstOrCreate(
            ['user_id' => $master->id, 'phone' => '+79001112233'],
            [
                'name' => 'Тест',
                'is_personal' => true,
            ],
        );

        $this->assertNotNull($client->id);
        $this->assertSame('+79001112233', $client->phone);
        $this->assertTrue($client->is_personal);
        $this->assertNull($client->workspace_id);

        $client2 = Client::firstOrCreate(
            ['user_id' => $master->id, 'phone' => '+79001112233'],
            ['name' => 'Дубликат'],
        );

        $this->assertSame($client->id, $client2->id, 'UNIQUE(user_id, phone) must prevent duplicates');
    }
}
