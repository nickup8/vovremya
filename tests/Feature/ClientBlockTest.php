<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use App\Services\Booking\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientBlockTest extends TestCase
{
    use RefreshDatabase;

    private User $masterA;

    private User $masterB;

    private Service $serviceA;

    private Service $serviceB;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.telegram.secret_token', 'test_webhook_secret');

        $this->masterA = User::factory()->master()->create();
        $this->masterB = User::factory()->master()->create();

        foreach ([$this->masterA, $this->masterB] as $master) {
            for ($day = 0; $day <= 6; $day++) {
                WorkingHour::updateOrCreate(
                    ['user_id' => $master->id, 'day_of_week' => $day],
                    [
                        'start_time' => '09:00',
                        'end_time' => '19:00',
                        'is_working' => true,
                        'break_start_time' => null,
                        'break_end_time' => null,
                    ]
                );
            }
        }

        $this->serviceA = Service::factory()->create([
            'user_id' => $this->masterA->id,
            'duration_minutes' => 60,
        ]);

        $this->serviceB = Service::factory()->create([
            'user_id' => $this->masterB->id,
            'duration_minutes' => 60,
        ]);
    }

    private function sendTelegramContact(int $chatId, string $phone, string $firstName = 'Test', ?string $pendingAppointmentId = null): void
    {
        Http::fake();

        if ($pendingAppointmentId !== null) {
            Cache::put("bot_pending:telegram:{$chatId}", $pendingAppointmentId, now()->addMinutes(15));
        }

        $payload = [
            'message' => [
                'chat' => ['id' => $chatId],
                'contact' => [
                    'phone_number' => $phone,
                    'user_id' => $chatId,
                    'first_name' => $firstName,
                ],
            ],
        ];

        $request = $this->postJson(route('webhooks.telegram'), $payload, [
            'X-Telegram-Bot-Api-Secret-Token' => 'test_webhook_secret',
        ]);

        $request->assertOk();
    }

    public function test_blocked_client_cannot_confirm_appointment(): void
    {
        $phone = '79001112233';

        Client::factory()->create([
            'user_id' => $this->masterA->id,
            'phone' => $phone,
            'is_blocked' => true,
        ]);

        $appointment = Appointment::create([
            'master_id' => $this->masterA->id,
            'client_id' => null,
            'service_id' => $this->serviceA->id,
            'start_time' => now()->addDay()->setTime(6, 0),
            'status' => 'booked',
            'provider' => 'telegram',
        ]);

        $this->sendTelegramContact(99999, $phone, 'Test', $appointment->id);

        $this->assertDatabaseMissing('appointments', ['id' => $appointment->id]);

        $startDateTime = new Carbon($appointment->start_time);
        $availability = app(AvailabilityService::class);
        $this->assertTrue(
            $availability->isSlotAvailable($this->masterA, $startDateTime->utc(), $this->serviceA->duration_minutes),
            'Slot should be available after appointment is deleted'
        );
    }

    public function test_same_phone_not_blocked_at_other_master_succeeds(): void
    {
        $phone = '79003334455';

        $clientB = Client::factory()->create([
            'user_id' => $this->masterB->id,
            'phone' => $phone,
            'is_blocked' => false,
        ]);

        $appointment = Appointment::create([
            'master_id' => $this->masterB->id,
            'client_id' => null,
            'service_id' => $this->serviceB->id,
            'start_time' => now()->addDay()->setTime(10, 0),
            'status' => 'booked',
            'provider' => 'telegram',
        ]);

        $this->sendTelegramContact(88888, $phone, 'Иван', $appointment->id);

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'client_id' => $clientB->id,
        ]);

        $appointment->refresh();
        $this->assertEquals('booked', $appointment->status->value);
    }

    public function test_toggle_block_own_client_sets_is_blocked(): void
    {
        $client = Client::factory()->create([
            'user_id' => $this->masterA->id,
            'is_blocked' => false,
        ]);

        $this->actingAs($this->masterA);

        $response = $this->post(route('admin.clients.toggle-block', $client->id));

        $response->assertRedirect();

        $client->refresh();
        $this->assertTrue($client->is_blocked);
    }

    public function test_toggle_block_other_masters_client_returns_403(): void
    {
        $client = Client::factory()->create([
            'user_id' => $this->masterB->id,
            'is_blocked' => false,
        ]);

        $this->actingAs($this->masterA);

        $response = $this->post(route('admin.clients.toggle-block', $client->id));

        $response->assertStatus(403);

        $client->refresh();
        $this->assertFalse($client->is_blocked);
    }
}
