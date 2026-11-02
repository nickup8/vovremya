<?php

namespace Tests\Feature;

use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
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
        Http::fake();

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('data-page', false);
        // INERTIA_SSR_ENABLED=false (по умолчанию) — диспатч SSR не вызывается.
        Http::assertNotSent(fn (HttpClientRequest $request) => $this->isSsrRequest($request));
    }

    public function test_booking_returns_csr_response(): void
    {
        $master = $this->createWidgetMaster();

        $response = $this->get("/book/{$master->master_slug}");

        $response->assertStatus(200);
        $response->assertSee('data-page', false);
    }

    public function test_home_dispatches_ssr_when_enabled(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.ensure_bundle_exists' => false,
        ]);
        Http::fake();

        $response = $this->get('/');

        $response->assertStatus(200);
        // Пустой ответ SSR-сервера — корректный CSR-фолбэк, страница работает.
        $response->assertSee('data-page', false);
        Http::assertSent(fn (HttpClientRequest $request) => $this->isSsrRequest($request));
    }

    public function test_non_landing_routes_stay_csr_even_when_ssr_enabled(): void
    {
        config([
            'inertia.ssr.enabled' => true,
            'inertia.ssr.ensure_bundle_exists' => false,
        ]);
        Http::fake();

        $master = $this->createWidgetMaster();

        $booking = $this->get("/book/{$master->master_slug}");
        $booking->assertStatus(200);
        $booking->assertSee('data-page', false);

        $legal = $this->get('/privacy');
        $legal->assertStatus(200);
        $legal->assertSee('data-page', false);

        Http::assertNotSent(fn (HttpClientRequest $request) => $this->isSsrRequest($request));
    }

    public function test_authenticated_home_redirects_to_calendar(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertRedirect(route('admin.calendar'));
    }

    public function test_home_exposes_today_prop_for_demo(): void
    {
        $response = $this->get('/');

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('welcome-v2')
            ->where('today', now()->toDateString()));
    }

    /**
     * Диспатч SSR-бандла: production-адрес (127.0.0.1:13714) либо dev-эндпоинт
     * Vite (/__inertia_ssr), когда существует public/hot.
     */
    private function isSsrRequest(HttpClientRequest $request): bool
    {
        $url = $request->url();

        return str_contains($url, '127.0.0.1:13714') || str_contains($url, '__inertia_ssr');
    }

    private function createWidgetMaster(): User
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

        return $master;
    }
}
