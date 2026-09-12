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

    // ═══════════════════════════════════════
    // findOrCreateByPhone — name protection
    // ═══════════════════════════════════════

    public function test_existing_real_name_preserved_when_incoming_null(): void
    {
        $master = User::factory()->master()->create();
        Client::factory()->create([
            'user_id' => $master->id,
            'phone' => '79001234567',
            'name' => 'Анна Петрова',
        ]);

        $client = $this->service->findOrCreateByPhone($master->id, '79001234567');

        $this->assertSame('Анна Петрова', $client->name);
    }

    public function test_existing_real_name_preserved_when_incoming_placeholder(): void
    {
        $master = User::factory()->master()->create();
        Client::factory()->create([
            'user_id' => $master->id,
            'phone' => '79001234567',
            'name' => 'Анна Петрова',
        ]);

        $client = $this->service->findOrCreateByPhone($master->id, '79001234567', '', 'Клиент');

        $this->assertSame('Анна Петрова', $client->name);
    }

    public function test_existing_real_name_preserved_when_incoming_phone_placeholder(): void
    {
        $master = User::factory()->master()->create();
        Client::factory()->create([
            'user_id' => $master->id,
            'phone' => '79001234567',
            'name' => 'Анна Петрова',
        ]);

        $client = $this->service->findOrCreateByPhone($master->id, '79001234567', '', 'Клиент 79001112233');

        $this->assertSame('Анна Петрова', $client->name);
    }

    public function test_existing_real_name_preserved_when_incoming_different_real_name(): void
    {
        $master = User::factory()->master()->create();
        Client::factory()->create([
            'user_id' => $master->id,
            'phone' => '79001234567',
            'name' => 'Анна Петрова',
        ]);

        $client = $this->service->findOrCreateByPhone($master->id, '79001234567', '', 'Анна Иванова');

        $this->assertSame('Анна Петрова', $client->name);
    }

    public function test_existing_placeholder_upgraded_to_real_name(): void
    {
        $master = User::factory()->master()->create();
        Client::factory()->create([
            'user_id' => $master->id,
            'phone' => '79001234567',
            'name' => 'Клиент',
        ]);

        $client = $this->service->findOrCreateByPhone($master->id, '79001234567', '', 'Анна Петрова');

        $this->assertSame('Анна Петрова', $client->name);
    }

    public function test_existing_phone_placeholder_upgraded_to_real_name(): void
    {
        $master = User::factory()->master()->create();
        Client::factory()->create([
            'user_id' => $master->id,
            'phone' => '79001234567',
            'name' => 'Клиент 79001112233',
        ]);

        $client = $this->service->findOrCreateByPhone($master->id, '79001234567', '', 'Анна Петрова');

        $this->assertSame('Анна Петрова', $client->name);
    }

    public function test_existing_placeholder_not_replaced_by_another_placeholder(): void
    {
        $master = User::factory()->master()->create();
        Client::factory()->create([
            'user_id' => $master->id,
            'phone' => '79001234567',
            'name' => 'Клиент',
        ]);

        $client = $this->service->findOrCreateByPhone($master->id, '79001234567', '', 'Клиент 79001112233');

        $this->assertSame('Клиент', $client->name);
    }

    // ═══════════════════════════════════════
    // findOrCreateByPhone — new client creation
    // ═══════════════════════════════════════

    public function test_new_client_with_null_name_gets_fallback(): void
    {
        $master = User::factory()->master()->create();

        $client = $this->service->findOrCreateByPhone($master->id, '79001234567');

        $this->assertSame(__('bot.fallback.client_name'), $client->name);
    }

    public function test_new_client_with_real_name_preserves_it(): void
    {
        $master = User::factory()->master()->create();

        $client = $this->service->findOrCreateByPhone($master->id, '79001234567', '', 'Анна Петрова');

        $this->assertSame('Анна Петрова', $client->name);
    }

    public function test_new_client_with_explicit_phone_placeholder_preserves_it(): void
    {
        $master = User::factory()->master()->create();

        $client = $this->service->findOrCreateByPhone($master->id, '79001234567', '', 'Клиент 79001112233');

        $this->assertSame('Клиент 79001112233', $client->name);
    }

    // ═══════════════════════════════════════
    // placeholder detection edge cases
    // ═══════════════════════════════════════

    public function test_klient_test_not_classified_as_placeholder(): void
    {
        $master = User::factory()->master()->create();
        Client::factory()->create([
            'user_id' => $master->id,
            'phone' => '79001234567',
            'name' => 'Клиент Тест',
        ]);

        // Incoming null should NOT overwrite "Клиент Тест" (it's a real name)
        $client = $this->service->findOrCreateByPhone($master->id, '79001234567');

        $this->assertSame('Клиент Тест', $client->name);
    }
}
