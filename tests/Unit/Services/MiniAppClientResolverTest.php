<?php

namespace Tests\Unit\Services;

use App\Models\Client;
use App\Models\User;
use App\Services\Auth\MaxInitDataResult;
use App\Services\Auth\VkLaunchParamsResult;
use App\Services\MiniAppClientResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class MiniAppClientResolverTest extends TestCase
{
    use RefreshDatabase;

    private MiniAppClientResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new MiniAppClientResolver();
    }

    private function makeMaxRequest(string $maxId): Request
    {
        $request = Request::create('/api/miniapp/test');
        $request->attributes->set('max_init', new MaxInitDataResult(
            userId: $maxId,
            authDate: time(),
            startParam: null,
            chatId: null,
            raw: [],
        ));

        return $request;
    }

    private function makeVkRequest(string $vkId): Request
    {
        $request = Request::create('/api/miniapp/test');
        $request->attributes->set('vk_launch', new VkLaunchParamsResult(
            userId: $vkId,
            appId: '6736218',
            timestamp: null,
            raw: [],
        ));

        return $request;
    }

    // ═══════════════════════════════════════════
    // MAX — resolveClientIds
    // ═══════════════════════════════════════════

    public function test_max_resolve_client_ids_returns_all_clients_across_masters(): void
    {
        $maxId = '111222333';
        $master1 = User::factory()->master()->create();
        $master2 = User::factory()->master()->create();

        $client1 = Client::factory()->create(['max_id' => $maxId, 'user_id' => $master1->id]);
        $client2 = Client::factory()->create(['max_id' => $maxId, 'user_id' => $master2->id]);

        $ids = $this->resolver->resolveClientIds($this->makeMaxRequest($maxId));

        $this->assertCount(2, $ids);
        $this->assertTrue($ids->contains($client1->id));
        $this->assertTrue($ids->contains($client2->id));
    }

    public function test_max_resolve_client_ids_excludes_foreign_max_id(): void
    {
        $master = User::factory()->master()->create();
        Client::factory()->create(['max_id' => '111', 'user_id' => $master->id]);

        $ids = $this->resolver->resolveClientIds($this->makeMaxRequest('999'));

        $this->assertTrue($ids->isEmpty());
    }

    public function test_max_resolve_client_ids_returns_empty_without_max_init(): void
    {
        $request = Request::create('/api/miniapp/test');

        $ids = $this->resolver->resolveClientIds($request);

        $this->assertTrue($ids->isEmpty());
    }

    // ═══════════════════════════════════════════
    // MAX — resolveFirstClient
    // ═══════════════════════════════════════════

    public function test_max_resolve_first_client_returns_matching_client(): void
    {
        $maxId = '555666777';
        $master = User::factory()->master()->create();
        $client = Client::factory()->create(['max_id' => $maxId, 'user_id' => $master->id]);

        $result = $this->resolver->resolveFirstClient($this->makeMaxRequest($maxId));

        $this->assertNotNull($result);
        $this->assertEquals($client->id, $result->id);
    }

    public function test_max_resolve_first_client_returns_null_without_max_init(): void
    {
        $request = Request::create('/api/miniapp/test');

        $result = $this->resolver->resolveFirstClient($request);

        $this->assertNull($result);
    }

    // ═══════════════════════════════════════════
    // VK — resolveClientIds
    // ═══════════════════════════════════════════

    public function test_vk_resolve_client_ids_returns_all_clients_across_masters(): void
    {
        $vkId = '494075';
        $master1 = User::factory()->master()->create();
        $master2 = User::factory()->master()->create();

        $client1 = Client::factory()->create(['vk_id' => $vkId, 'user_id' => $master1->id]);
        $client2 = Client::factory()->create(['vk_id' => $vkId, 'user_id' => $master2->id]);

        $ids = $this->resolver->resolveClientIds($this->makeVkRequest($vkId));

        $this->assertCount(2, $ids);
        $this->assertTrue($ids->contains($client1->id));
        $this->assertTrue($ids->contains($client2->id));
    }

    public function test_vk_resolve_client_ids_excludes_foreign_vk_id(): void
    {
        $master = User::factory()->master()->create();
        Client::factory()->create(['vk_id' => '111', 'user_id' => $master->id]);

        $ids = $this->resolver->resolveClientIds($this->makeVkRequest('999'));

        $this->assertTrue($ids->isEmpty());
    }

    // ═══════════════════════════════════════════
    // VK — resolveFirstClient
    // ═══════════════════════════════════════════

    public function test_vk_resolve_first_client_returns_matching_client(): void
    {
        $vkId = '494075';
        $master = User::factory()->master()->create();
        $client = Client::factory()->create(['vk_id' => $vkId, 'user_id' => $master->id]);

        $result = $this->resolver->resolveFirstClient($this->makeVkRequest($vkId));

        $this->assertNotNull($result);
        $this->assertEquals($client->id, $result->id);
    }

    // ═══════════════════════════════════════════
    // NEITHER — empty request
    // ═══════════════════════════════════════════

    public function test_returns_empty_without_any_identity(): void
    {
        $request = Request::create('/api/miniapp/test');

        $this->assertTrue($this->resolver->resolveClientIds($request)->isEmpty());
        $this->assertNull($this->resolver->resolveFirstClient($request));
    }
}
