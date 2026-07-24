<?php

namespace Tests\Feature\Billing;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use App\Services\Billing\TariffLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TariffLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markTestSkipped('Устаревший тест: колонка tariff удалена из users');

        $this->master = User::factory()->master()->create(['tariff' => 'free']);
        $this->service = Service::factory()->for($this->master)->create(['duration_minutes' => 60]);

        for ($day = 0; $day < 7; $day++) {
            WorkingHour::updateOrCreate(
                ['user_id' => $this->master->id, 'day_of_week' => $day],
                ['is_working' => true, 'start_time' => '08:00', 'end_time' => '20:00']
            );
        }
    }

    public function test_free_tariff_allows_up_to_30_appointments(): void
    {
        $client = Client::factory()->for($this->master)->create();

        for ($i = 0; $i < 30; $i++) {
            Appointment::factory()
                ->forMaster($this->master)
                ->forClient($client)
                ->withService($this->service)
                ->booked()
                ->create([
                    'start_time' => now()->startOfMonth()->addDays($i)->setTime(10, 0),
                ]);
        }

        $this->assertDatabaseCount('appointments', 30);

        $response = $this->actingAs($this->master)->post(route('booking.reserve', $this->master->master_slug), [
            'client_name' => 'Тестовый Клиент',
            'client_phone' => '+79000000099',
            'service_id' => $this->service->id,
            'date' => now()->addDays(31)->format('Y-m-d'),
            'time' => '10:00',
            'provider' => 'telegram',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('time');
        $this->assertDatabaseCount('appointments', 30);
    }

    public function test_pro_tariff_allows_unlimited_appointments(): void
    {
        $this->master->update(['tariff' => 'pro']);
        $client = Client::factory()->for($this->master)->create();

        for ($i = 0; $i < 35; $i++) {
            Appointment::factory()
                ->forMaster($this->master)
                ->forClient($client)
                ->withService($this->service)
                ->booked()
                ->create([
                    'start_time' => now()->startOfMonth()->addDays($i)->setTime(10, 0),
                ]);
        }

        $this->assertDatabaseCount('appointments', 35);
    }

    public function test_free_tariff_counts_only_booked_and_paid(): void
    {
        $client = Client::factory()->for($this->master)->create();

        for ($i = 0; $i < 25; $i++) {
            Appointment::factory()
                ->forMaster($this->master)
                ->forClient($client)
                ->withService($this->service)
                ->booked()
                ->create([
                    'start_time' => now()->startOfMonth()->addDays($i)->setTime(10, 0),
                ]);
        }

        for ($i = 0; $i < 10; $i++) {
            Appointment::factory()
                ->forMaster($this->master)
                ->forClient($client)
                ->withService($this->service)
                ->cancelled()
                ->create([
                    'start_time' => now()->startOfMonth()->addDays($i)->setTime(14, 0),
                ]);
        }

        $this->assertDatabaseCount('appointments', 35);

        $this->assertTrue(
            app(TariffLimitService::class)->canCreateAppointment($this->master)
        );
    }
}
