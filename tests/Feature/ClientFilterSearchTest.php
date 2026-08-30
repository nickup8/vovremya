<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientFilterSearchTest extends TestCase
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

    private function createClient(string $name, string $phone, bool $blocked = false): Client
    {
        return Client::create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->workspace->id,
            'name' => $name,
            'phone' => $phone,
            'is_blocked' => $blocked,
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

    public function test_active_filter_works_across_entire_dataset(): void
    {
        $this->actingAs($this->master);

        $this->createClient('Активный', '+79001110001', false);
        $this->createClient('Заблокирован', '+79001110002', true);
        $this->createClient('Ещё активный', '+79001110003', false);

        $response = $this->get(route('admin.clients', ['filter' => 'active', 'per_page' => 20]));

        $response->assertInertia(fn ($page) => $page
            ->where('clients.total', 2)
        );
    }

    public function test_blocked_filter_works_across_entire_dataset(): void
    {
        $this->actingAs($this->master);

        $this->createClient('Активный', '+79001110001', false);
        $this->createClient('Заблокирован', '+79001110002', true);
        $this->createClient('Ещё один блок', '+79001110003', true);

        $response = $this->get(route('admin.clients', ['filter' => 'blocked', 'per_page' => 20]));

        $response->assertInertia(fn ($page) => $page
            ->where('clients.total', 2)
        );
    }

    public function test_total_changes_after_filter(): void
    {
        $this->actingAs($this->master);

        $this->createClient('А', '+79001110001', false);
        $this->createClient('Б', '+79001110002', true);
        $this->createClient('В', '+79001110003', false);

        $all = $this->get(route('admin.clients', ['per_page' => 20]));
        $all->assertInertia(fn ($page) => $page->where('clients.total', 3));

        $active = $this->get(route('admin.clients', ['filter' => 'active', 'per_page' => 20]));
        $active->assertInertia(fn ($page) => $page->where('clients.total', 2));

        $blocked = $this->get(route('admin.clients', ['filter' => 'blocked', 'per_page' => 20]));
        $blocked->assertInertia(fn ($page) => $page->where('clients.total', 1));
    }

    public function test_pagination_builds_after_filter(): void
    {
        $this->actingAs($this->master);

        for ($i = 0; $i < 5; $i++) {
            $this->createClient("Active $i", '+7900111' . str_pad((string) $i, 3, '0', STR_PAD_LEFT), false);
        }
        $this->createClient('Blocked', '+79001110099', true);

        $response = $this->get(route('admin.clients', ['filter' => 'active', 'per_page' => 3]));

        $response->assertInertia(fn ($page) => $page
            ->where('clients.total', 5)
            ->where('clients.last_page', 2)
            ->where('clients.per_page', 3)
        );
    }

    public function test_search_name_across_pages(): void
    {
        $this->actingAs($this->master);

        $this->createClient('Анна Иванова', '+79001110001');
        $this->createClient('Борис Петров', '+79001110002');
        $this->createClient('Анна Сидорова', '+79001110003');
        $this->createClient('Вера Козлова', '+79001110004');

        $response = $this->get(route('admin.clients', ['search' => 'Анна', 'per_page' => 20]));

        $response->assertInertia(fn ($page) => $page
            ->where('clients.total', 2)
        );
    }

    public function test_search_phone_across_pages(): void
    {
        $this->actingAs($this->master);

        $this->createClient('Клиент А', '+79001234567');
        $this->createClient('Клиент Б', '+79009876543');
        $this->createClient('Клиент В', '+79001239999');

        $response = $this->get(route('admin.clients', ['search' => '123', 'per_page' => 20]));

        $response->assertInertia(fn ($page) => $page
            ->where('clients.total', 2)
        );
    }

    public function test_sort_applies_after_filter(): void
    {
        $this->actingAs($this->master);

        $this->createClient('Вера', '+79001110003', false);
        $this->createClient('Анна', '+79001110001', false);
        $this->createClient('Заблок', '+79001110004', true);
        $this->createClient('Борис', '+79001110002', false);

        $response = $this->get(route('admin.clients', [
            'filter' => 'active',
            'sort' => 'name_asc',
            'per_page' => 20,
        ]));

        $response->assertInertia(fn ($page) => $page
            ->where('clients.total', 3)
            ->where('clients.data.0.name', 'Анна')
            ->where('clients.data.1.name', 'Борис')
            ->where('clients.data.2.name', 'Вера')
        );
    }

    public function test_null_visit_clients_sort_last_in_last_visit_desc(): void
    {
        $this->actingAs($this->master);

        $withVisit = $this->createClient('С визитом', '+79001110001');
        $noVisit = $this->createClient('Без визита', '+79001110002');
        $oldVisit = $this->createClient('Старый визит', '+79001110003');

        $this->addPaidVisit($withVisit, '2026-06-01 10:00:00');
        $this->addPaidVisit($oldVisit, '2026-01-01 10:00:00');

        $response = $this->get(route('admin.clients', ['sort' => 'last_visit_desc', 'per_page' => 20]));

        $response->assertInertia(fn ($page) => $page
            ->where('clients.data.0.name', 'С визитом')
            ->where('clients.data.1.name', 'Старый визит')
            ->where('clients.data.2.name', 'Без визита')
        );
    }

    public function test_query_params_produce_correct_results(): void
    {
        $this->actingAs($this->master);

        $this->createClient('Анна Активная', '+79001110001', false);
        $this->createClient('Анна Заблокированная', '+79001110002', true);
        $this->createClient('Борис Активный', '+79001110003', false);

        // Search "Анна" + filter "active" → 1 result
        $response = $this->get(route('admin.clients', [
            'search' => 'Анна',
            'filter' => 'active',
            'per_page' => 20,
        ]));

        $response->assertInertia(fn ($page) => $page
            ->where('clients.total', 1)
            ->where('clients.data.0.name', 'Анна Активная')
        );

        // Search "Анна" + filter "blocked" → 1 result
        $response2 = $this->get(route('admin.clients', [
            'search' => 'Анна',
            'filter' => 'blocked',
            'per_page' => 20,
        ]));

        $response2->assertInertia(fn ($page) => $page
            ->where('clients.total', 1)
            ->where('clients.data.0.name', 'Анна Заблокированная')
        );
    }
}
