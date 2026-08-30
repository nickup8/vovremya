<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientNotesTest extends TestCase
{
    use RefreshDatabase;

    private User $master;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::create([
            'name' => 'Studio',
            'owner_id' => User::factory()->create()->id,
        ]);

        $this->master = User::factory()->master()->create([
            'workspace_id' => $this->workspace->id,
            'role' => 'owner',
        ]);
    }

    public function test_create_client_persists_notes(): void
    {
        $this->actingAs($this->master);

        $response = $this->post(route('admin.clients.store'), [
            'name' => 'Анна',
            'phone' => '+79001112233',
            'notes' => 'Любит кофе',
        ]);

        $response->assertStatus(200);

        $client = Client::where('phone', '+79001112233')->first();
        $this->assertNotNull($client);
        $this->assertSame('Любит кофе', $client->notes);
    }

    public function test_create_client_allows_empty_notes(): void
    {
        $this->actingAs($this->master);

        $response = $this->post(route('admin.clients.store'), [
            'name' => 'Борис',
            'phone' => '+79001112244',
        ]);

        $response->assertStatus(200);

        $client = Client::where('phone', '+79001112244')->first();
        $this->assertNotNull($client);
        $this->assertNull($client->notes);
    }

    public function test_update_client_changes_notes(): void
    {
        $this->actingAs($this->master);

        $client = Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->workspace->id,
            'name' => 'Вера',
            'phone' => '+79001112255',
            'notes' => 'Старая заметка',
        ]);

        $response = $this->put(route('admin.clients.update', $client), [
            'name' => 'Вера',
            'phone' => '+79001112255',
            'notes' => 'Новая заметка',
        ]);

        $response->assertRedirect();

        $client->refresh();
        $this->assertSame('Новая заметка', $client->notes);
    }

    public function test_update_preserves_expected_notes_value(): void
    {
        $this->actingAs($this->master);

        $client = Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->workspace->id,
            'name' => 'Глеб',
            'phone' => '+79001112266',
            'notes' => 'Важный клиент',
        ]);

        $this->put(route('admin.clients.update', $client), [
            'name' => 'Глеб Иванов',
            'phone' => '+79001112266',
            'notes' => 'Очень важный клиент',
        ]);

        $client->refresh();
        $this->assertSame('Очень важный клиент', $client->notes);
        $this->assertSame('Глеб Иванов', $client->name);
    }

    public function test_clients_page_payload_contains_notes(): void
    {
        $this->actingAs($this->master);

        Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->workspace->id,
            'name' => 'Дарья',
            'phone' => '+79001112277',
            'notes' => 'Заметка в payload',
        ]);

        $response = $this->get(route('admin.clients'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('clients.data.0.notes', 'Заметка в payload')
        );
    }
}
