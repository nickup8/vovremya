<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientSortPaginationTest extends TestCase
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

    private function createClient(string $name, string $phone): Client
    {
        return Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->workspace->id,
            'name' => $name,
            'phone' => $phone,
        ]);
    }

    private function addPaidVisit(Client $client, string $date): void
    {
        Appointment::create([
            'master_id' => $this->master->id,
            'client_id' => $client->id,
            'service_name' => 'Стрижка',
            'price' => 1000,
            'duration' => 60,
            'start_time' => $date,
            'status' => AppointmentStatus::Paid,
        ]);
    }

    public function test_default_sort_returns_globally_ordered_clients(): void
    {
        $this->actingAs($this->master);

        $c1 = $this->createClient('Анна', '+79001110001');
        $c2 = $this->createClient('Борис', '+79001110002');
        $c3 = $this->createClient('Вера', '+79001110003');

        $this->addPaidVisit($c2, '2026-01-10 10:00:00');
        $this->addPaidVisit($c1, '2026-03-15 10:00:00');
        $this->addPaidVisit($c3, '2026-02-01 10:00:00');

        $response = $this->get(route('admin.clients'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('clients.data.0.name', 'Анна')
            ->where('clients.data.1.name', 'Вера')
            ->where('clients.data.2.name', 'Борис')
        );
    }

    public function test_name_asc_sort_returns_globally_ordered_clients(): void
    {
        $this->actingAs($this->master);

        $this->createClient('Вера', '+79001110003');
        $this->createClient('Анна', '+79001110001');
        $this->createClient('Борис', '+79001110002');

        $response = $this->get(route('admin.clients', ['sort' => 'name_asc']));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('clients.data.0.name', 'Анна')
            ->where('clients.data.1.name', 'Борис')
            ->where('clients.data.2.name', 'Вера')
        );
    }

    public function test_sorting_happens_before_pagination(): void
    {
        $this->actingAs($this->master);

        // Create 25 clients: "ААА" through "ААЯ" (Cyrillic) so name_asc is deterministic
        $names = [];
        for ($i = 0; $i < 25; $i++) {
            $names[] = 'А' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
        }

        foreach ($names as $idx => $name) {
            $this->createClient($name, '+7900111' . str_pad((string) $idx, 3, '0', STR_PAD_LEFT));
        }

        // Page 1 with name_asc — first 20 alphabetically
        $response = $this->get(route('admin.clients', ['sort' => 'name_asc', 'per_page' => 20]));
        $response->assertStatus(200);

        // Last item on page 1 should be "А20"
        $response->assertInertia(fn ($page) => $page
            ->has('clients.data', 20)
            ->where('clients.data.19.name', 'А20')
        );

        // Page 2 — remaining 5, first should be "А21"
        $response2 = $this->get(route('admin.clients', ['sort' => 'name_asc', 'per_page' => 20, 'page' => 2]));
        $response2->assertInertia(fn ($page) => $page
            ->has('clients.data', 5)
            ->where('clients.data.0.name', 'А21')
            ->where('clients.data.4.name', 'А25')
        );
    }

    public function test_invalid_sort_falls_back_safely(): void
    {
        $this->actingAs($this->master);

        $c1 = $this->createClient('Анна', '+79001110001');
        $c2 = $this->createClient('Борис', '+79001110002');

        $this->addPaidVisit($c1, '2026-03-15 10:00:00');
        $this->addPaidVisit($c2, '2026-01-10 10:00:00');

        // Invalid sort should fallback to last_visit_desc
        $response = $this->get(route('admin.clients', ['sort' => 'invalid_sort']));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('clients.data.0.name', 'Анна')
            ->where('clients.data.1.name', 'Борис')
        );
    }

    public function test_pagination_returns_correct_metadata(): void
    {
        $this->actingAs($this->master);

        for ($i = 0; $i < 25; $i++) {
            $this->createClient('Клиент ' . $i, '+7900111' . str_pad((string) $i, 3, '0', STR_PAD_LEFT));
        }

        $response = $this->get(route('admin.clients', ['per_page' => 20]));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('clients.total', 25)
            ->where('clients.per_page', 20)
            ->where('clients.current_page', 1)
            ->where('clients.last_page', 2)
            ->where('clients.from', 1)
            ->where('clients.to', 20)
        );
    }
}
