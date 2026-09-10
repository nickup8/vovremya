<?php

namespace Tests\Unit\Services;

use App\Models\Client;
use App\Models\User;
use App\Services\Auth\MaxInitDataResult;
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

    private function makeRequest(string $maxId): Request
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

    public function test_resolve_client_ids_returns_all_clients_across_masters(): void
    {
        $maxId = '111222333';
        $master1 = User::factory()->master()->create();
        $master2 = User::factory()->master()->create();

        $client1 = Client::factory()->create(['max_id' => $maxId, 'user_id' => $master1->id]);
        $client2 = Client::factory()->create(['max_id' => $maxId, 'user_id' => $master2->id]);

        $ids = $this->resolver->resolveClientIds($this->makeRequest($maxId));

        $this->assertCount(2, $ids);
        $this->assertTrue($ids->contains($client1->id));
        $this->assertTrue($ids->contains($client2->id));
    }

    public function test_resolve_client_ids_excludes_foreign_max_id(): void
    {
        $master = User::factory()->master()->create();
        Client::factory()->create(['max_id' => '111', 'user_id' => $master->id]);

        $ids = $this->resolver->resolveClientIds($this->makeRequest('999'));

        $this->assertTrue($ids->isEmpty());
    }

    public function test_resolve_client_ids_returns_empty_without_max_init(): void
    {
        $request = Request::create('/api/miniapp/test');
        // no max_init set

        $ids = $this->resolver->resolveClientIds($request);

        $this->assertTrue($ids->isEmpty());
    }

    public function test_resolve_first_client_returns_matching_client(): void
    {
        $maxId = '555666777';
        $master = User::factory()->master()->create();
        $client = Client::factory()->create(['max_id' => $maxId, 'user_id' => $master->id]);

        $result = $this->resolver->resolveFirstClient($this->makeRequest($maxId));

        $this->assertNotNull($result);
        $this->assertEquals($client->id, $result->id);
    }

    public function test_resolve_first_client_returns_null_without_max_init(): void
    {
        $request = Request::create('/api/miniapp/test');
        // no max_init set

        $result = $this->resolver->resolveFirstClient($request);

        $this->assertNull($result);
    }
}
