<?php

namespace Tests\Unit\Services\Client;

use App\Models\Client;
use App\Models\User;
use App\Services\Client\ClientMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientMergeServiceTest extends TestCase
{
    use RefreshDatabase;

    private ClientMergeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ClientMergeService();
    }

    public function test_vk_linking_sets_vk_id(): void
    {
        $master = User::factory()->master()->create();
        $client = Client::factory()->create(['user_id' => $master->id, 'vk_id' => null]);

        $this->service->linkProvider($client, 'vk', '12345');

        $this->assertEquals('12345', $client->fresh()->vk_id);
    }

    public function test_vk_linking_preserves_existing_telegram_and_max_ids(): void
    {
        $master = User::factory()->master()->create();
        $client = Client::factory()->create([
            'user_id' => $master->id,
            'telegram_id' => '999',
            'max_id' => '888',
            'vk_id' => null,
        ]);

        $this->service->linkProvider($client, 'vk', '12345');

        $fresh = $client->fresh();
        $this->assertEquals('12345', $fresh->vk_id);
        $this->assertEquals('999', $fresh->telegram_id);
        $this->assertEquals('888', $fresh->max_id);
    }

    public function test_unknown_provider_is_noop(): void
    {
        $master = User::factory()->master()->create();
        $client = Client::factory()->create(['user_id' => $master->id, 'vk_id' => null]);

        $this->service->linkProvider($client, 'unknown', '12345');

        $this->assertNull($client->fresh()->vk_id);
    }

    public function test_empty_vk_provider_id_is_noop(): void
    {
        $master = User::factory()->master()->create();
        $client = Client::factory()->create(['user_id' => $master->id, 'vk_id' => null]);

        $this->service->linkProvider($client, 'vk', '');

        $this->assertNull($client->fresh()->vk_id);
    }

    public function test_existing_max_linking_still_works(): void
    {
        $master = User::factory()->master()->create();
        $client = Client::factory()->create(['user_id' => $master->id, 'max_id' => null]);

        $this->service->linkProvider($client, 'max', '777');

        $this->assertEquals('777', $client->fresh()->max_id);
    }

    public function test_existing_telegram_linking_still_works(): void
    {
        $master = User::factory()->master()->create();
        $client = Client::factory()->create(['user_id' => $master->id, 'telegram_id' => null]);

        $this->service->linkProvider($client, 'telegram', '555');

        $this->assertEquals('555', $client->fresh()->telegram_id);
    }
}
