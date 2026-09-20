<?php

namespace Tests\Unit\Services;

use App\Enums\AppointmentSource;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\User;
use App\Services\Notification\ClientNotificationService;
use App\Services\VkApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ClientNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ClientNotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ClientNotificationService();
    }

    public function test_vk_source_with_vk_id_uses_vk_send(): void
    {
        $master = User::factory()->master()->create();
        $client = Client::factory()->create([
            'user_id' => $master->id,
            'vk_id' => '12345',
        ]);
        $ms = MasterService::factory()->forMaster($master)->create();
        $appointment = Appointment::factory()
            ->forMaster($master)
            ->forClient($client)
            ->withMasterService($ms)
            ->create([
                'source' => AppointmentSource::Vk,
                'start_time' => Carbon::tomorrow()->setTime(10, 0),
            ]);

        $mock = $this->mock(VkApiClient::class);
        $mock->shouldReceive('sendMessage')
            ->once()
            ->with('12345', 'test notification text')
            ->andReturn('123');

        $this->service->sendToClientBySource($appointment, 'test notification text');
    }
}
