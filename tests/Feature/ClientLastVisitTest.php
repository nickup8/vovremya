<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Контракт last_visit в базе клиентов:
 * MAX(start_time) только по прошедшим записям со статусом Paid.
 * Дата отдаётся в часовом поясе мастера (Europe/Moscow в тестах).
 */
class ClientLastVisitTest extends TestCase
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
        $this->master->setTimezone('Europe/Moscow');

        // «Сейчас» зафиксировано: 2026-06-15 12:00 UTC = 2026-06-15 15:00 в Europe/Moscow.
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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

    /**
     * @param  string  $startUtc  start_time в UTC (хранение в UTC), 60 минут длительности.
     */
    private function addVisit(
        Client $client,
        string $startUtc,
        AppointmentStatus $status,
        ?string $completedAt = null,
    ): Appointment {
        return Appointment::create([
            'master_id' => $this->master->id,
            'client_id' => $client->id,
            'service_name' => 'Стрижка',
            'price' => 1000,
            'duration' => 60,
            'start_time' => $startUtc,
            'status' => $status,
            'completed_at' => $completedAt,
        ]);
    }

    private function clientsPage(array $query = []): TestResponse
    {
        return $this->get(route('admin.clients', array_merge(['per_page' => 20], $query)));
    }

    public function test_past_paid_wins_over_later_future_booking(): void
    {
        $this->actingAs($this->master);

        $client = $this->createClient('Анна', '+79001110001');
        $this->addVisit($client, '2026-06-01 07:00:00', AppointmentStatus::Paid);
        $this->addVisit($client, '2026-07-20 07:00:00', AppointmentStatus::Booked);

        $response = $this->clientsPage();

        $response->assertStatus(200);
        // 2026-06-01 07:00 UTC = 2026-06-01 10:00 в Europe/Moscow.
        $response->assertInertia(fn ($page) => $page
            ->where('clients.data.0.last_visit', '2026-06-01 10:00:00')
        );
    }

    public function test_future_paid_is_excluded(): void
    {
        $this->actingAs($this->master);

        $withPast = $this->createClient('Прошлый Paid', '+79001110001');
        $this->addVisit($withPast, '2026-06-01 07:00:00', AppointmentStatus::Paid);
        $this->addVisit($withPast, '2026-06-20 07:00:00', AppointmentStatus::Paid);

        $onlyFuture = $this->createClient('Только будущий', '+79001110002');
        $this->addVisit($onlyFuture, '2026-06-21 07:00:00', AppointmentStatus::Paid);

        $response = $this->clientsPage();

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('clients.data.0.name', 'Прошлый Paid')
            ->where('clients.data.0.last_visit', '2026-06-01 10:00:00')
            ->where('clients.data.1.name', 'Только будущий')
            ->where('clients.data.1.last_visit', null)
        );
    }

    public function test_later_non_paid_statuses_do_not_change_last_visit(): void
    {
        $this->actingAs($this->master);

        $client = $this->createClient('Борис', '+79001110003');
        $this->addVisit($client, '2026-06-01 07:00:00', AppointmentStatus::Paid);

        // Более поздние записи всех остальных статусов — не визиты.
        $this->addVisit($client, '2026-06-05 07:00:00', AppointmentStatus::Cancelled);
        $this->addVisit($client, '2026-06-06 07:00:00', AppointmentStatus::NoShow);
        $this->addVisit($client, '2026-06-07 07:00:00', AppointmentStatus::Booked);
        $this->addVisit($client, '2026-06-08 07:00:00', AppointmentStatus::Prepaid);
        $this->addVisit($client, '2026-06-09 07:00:00', AppointmentStatus::PendingPayment);

        $response = $this->clientsPage();

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('clients.data.0.last_visit', '2026-06-01 10:00:00')
        );
    }

    public function test_max_of_multiple_past_paid_is_used(): void
    {
        $this->actingAs($this->master);

        $client = $this->createClient('Вера', '+79001110004');
        $this->addVisit($client, '2026-05-20 07:00:00', AppointmentStatus::Paid);
        $this->addVisit($client, '2026-06-10 07:00:00', AppointmentStatus::Paid);
        $this->addVisit($client, '2026-06-02 07:00:00', AppointmentStatus::Paid);

        $response = $this->clientsPage();

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('clients.data.0.last_visit', '2026-06-10 10:00:00')
        );
    }

    public function test_completed_at_does_not_affect_last_visit(): void
    {
        $this->actingAs($this->master);

        // Старый визит отмечен Paid позже нового: порядок и значение — по start_time.
        $old = $this->createClient('Старый визит', '+79001110005');
        $this->addVisit($old, '2026-05-01 07:00:00', AppointmentStatus::Paid, '2026-06-14 00:00:00');

        $new = $this->createClient('Новый визит', '+79001110006');
        $this->addVisit($new, '2026-06-10 07:00:00', AppointmentStatus::Paid, '2026-06-10 12:00:00');

        $response = $this->clientsPage();

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('clients.data.0.name', 'Новый визит')
            ->where('clients.data.0.last_visit', '2026-06-10 10:00:00')
            ->where('clients.data.1.name', 'Старый визит')
            ->where('clients.data.1.last_visit', '2026-05-01 10:00:00')
        );
    }

    public function test_no_qualifying_visits_returns_null(): void
    {
        $this->actingAs($this->master);

        $client = $this->createClient('Гость', '+79001110007');
        $this->addVisit($client, '2026-05-10 07:00:00', AppointmentStatus::Cancelled);
        $this->addVisit($client, '2026-07-10 07:00:00', AppointmentStatus::Booked);

        $this->createClient('Без записей', '+79001110008');

        $response = $this->clientsPage();

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('clients.total', 2)
            ->where('clients.data.0.last_visit', null)
            ->where('clients.data.1.last_visit', null)
        );
    }

    public function test_nulls_sorted_last_across_pages(): void
    {
        $this->actingAs($this->master);

        $withVisit1 = $this->createClient('Визит 1', '+79001110010');
        $this->addVisit($withVisit1, '2026-06-11 07:00:00', AppointmentStatus::Paid);
        $withVisit2 = $this->createClient('Визит 2', '+79001110011');
        $this->addVisit($withVisit2, '2026-06-12 07:00:00', AppointmentStatus::Paid);
        $withVisit3 = $this->createClient('Визит 3', '+79001110012');
        $this->addVisit($withVisit3, '2026-06-13 07:00:00', AppointmentStatus::Paid);

        // Нет подходящих визитов: null → в конец списка, включая границу пагинации.
        $this->createClient('Без визита 1', '+79001110013');
        $this->createClient('Без визита 2', '+79001110014');

        $page1 = $this->clientsPage(['sort' => 'last_visit_desc', 'per_page' => 3]);
        $page1->assertStatus(200);
        $page1->assertInertia(fn ($page) => $page
            ->where('clients.total', 5)
            ->where('clients.current_page', 1)
            ->where('clients.data.0.name', 'Визит 3')
            ->where('clients.data.0.last_visit', '2026-06-13 10:00:00')
            ->where('clients.data.1.name', 'Визит 2')
            ->where('clients.data.2.name', 'Визит 1')
        );

        $page2 = $this->clientsPage(['sort' => 'last_visit_desc', 'per_page' => 3, 'page' => 2]);
        $page2->assertStatus(200);
        $page2->assertInertia(fn ($page) => $page
            ->where('clients.current_page', 2)
            ->where('clients.data.0.last_visit', null)
            ->where('clients.data.1.last_visit', null)
        );
    }

    public function test_last_visit_uses_master_timezone_near_day_boundary(): void
    {
        $this->actingAs($this->master);

        $client = $this->createClient('Ночная', '+79001110015');
        // 2026-06-01 22:30 UTC = 2026-06-02 01:30 в Europe/Moscow: для мастера это уже 2 июня.
        $this->addVisit($client, '2026-06-01 22:30:00', AppointmentStatus::Paid);

        $response = $this->clientsPage();

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('clients.data.0.last_visit', '2026-06-02 01:30:00')
        );
    }
}
