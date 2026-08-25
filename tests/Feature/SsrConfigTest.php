<?php

namespace Tests\Feature;

use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SsrConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_ssr_disabled_by_default(): void
    {
        $this->assertFalse(config('inertia.ssr.enabled'));
    }

    public function test_home_returns_csr_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('data-page', false);
    }

    public function test_booking_returns_csr_response(): void
    {
        $master = User::factory()->master()->create([
            'workspace_id' => null,
            'master_slug' => 'ssr-test-master',
            'is_service_provider' => true,
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);

        $ws = Workspace::create(['name' => 'SSR Test WS', 'owner_id' => $master->id]);
        $catalog = ServiceCatalog::create(['workspace_id' => $ws->id, 'title' => 'Стрижка', 'base_price' => 1000, 'base_duration' => 60]);
        MasterService::create(['master_id' => $master->id, 'catalog_id' => $catalog->id, 'is_active' => true]);

        $response = $this->get("/book/{$master->master_slug}");

        $response->assertStatus(200);
        $response->assertSee('data-page', false);
    }
}
