<?php

namespace Tests\Feature\Seo;

use App\Models\Client;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\TrackingLink;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RobotsNoindexTest extends TestCase
{
    use RefreshDatabase;

    private function createWidgetMaster(): User
    {
        $master = User::factory()->master()->create([
            'workspace_id' => null,
            'master_slug' => 'seo-test-master',
            'is_service_provider' => true,
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);

        $ws = Workspace::create(['name' => 'SEO Test WS', 'owner_id' => $master->id]);
        $catalog = ServiceCatalog::create(['workspace_id' => $ws->id, 'title' => 'Стрижка', 'base_price' => 1000, 'base_duration' => 60]);
        MasterService::create(['master_id' => $master->id, 'catalog_id' => $catalog->id, 'is_active' => true]);

        return $master;
    }

    // ─── Главная и ресурсы: без noindex ───

    public function test_guest_home_has_no_noindex_header(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_authenticated_home_redirect_has_no_noindex_header(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/');

        $response->assertRedirect(route('admin.calendar'));
        $response->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_static_resources_have_no_noindex_header(): void
    {
        $this->get('/favicon.ico')->assertHeaderMissing('X-Robots-Tag');
        $this->get('/storage/missing.png')->assertHeaderMissing('X-Robots-Tag');
        $this->get('/sitemap.xml')->assertHeaderMissing('X-Robots-Tag');
        $this->get('/robots.txt')->assertHeaderMissing('X-Robots-Tag');
    }

    // ─── Правовые страницы: noindex, без canonical ───

    public function test_offer_page_is_noindexed_without_canonical(): void
    {
        $response = $this->get('/offer');

        $response->assertOk();
        $response->assertHeader('X-Robots-Tag', 'noindex');
        $response->assertDontSee('rel="canonical"', false);
    }

    public function test_privacy_page_is_noindexed_without_canonical(): void
    {
        $response = $this->get('/privacy');

        $response->assertOk();
        $response->assertHeader('X-Robots-Tag', 'noindex');
        $response->assertDontSee('rel="canonical"', false);
    }

    // ─── Виджет и available-dates: noindex при любых параметрах ───

    public function test_booking_widget_is_noindexed_with_all_query_params(): void
    {
        $master = $this->createWidgetMaster();
        $service = MasterService::where('master_id', $master->id)->firstOrFail();

        $response = $this->get("/book/{$master->master_slug}?service_id={$service->id}&date=".now()->toDateString().'&fw=nonexistent-token');

        $response->assertOk();
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }

    public function test_available_dates_endpoint_is_noindexed(): void
    {
        $master = $this->createWidgetMaster();
        $service = MasterService::where('master_id', $master->id)->firstOrFail();

        $response = $this->getJson("/book/{$master->master_slug}/available-dates?service_id={$service->id}&year=2026&month=6");

        $response->assertOk();
        $response->assertJsonStructure(['dates', 'publicationExpired']);
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }

    public function test_unknown_master_widget_404_is_noindexed(): void
    {
        $response = $this->get('/book/no-such-master');

        $response->assertNotFound();
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }

    // ─── Мини-приложения: header + сохранённый meta ───

    public function test_max_app_is_noindexed_and_keeps_meta_robots(): void
    {
        $response = $this->get('/max-app');

        $response->assertOk();
        $response->assertHeader('X-Robots-Tag', 'noindex');
        $response->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }

    public function test_vk_app_is_noindexed_and_keeps_meta_robots(): void
    {
        $response = $this->get('/vk-app');

        $response->assertOk();
        $response->assertHeader('X-Robots-Tag', 'noindex');
        $response->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }

    // ─── Гостевые редиректы кабинетов: noindex, Location и статус неизменны ───

    public function test_guest_admin_cabinet_redirect_is_noindexed(): void
    {
        $response = $this->get('/admin/calendar');

        $response->assertRedirect(route('login'));
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }

    public function test_guest_client_cabinet_redirect_is_noindexed(): void
    {
        $response = $this->get('/client/my-bookings');

        $response->assertRedirect(route('login'));
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }

    // ─── Авторизационные маршруты: noindex, статусы неизменны ───

    public function test_login_redirect_is_noindexed(): void
    {
        $response = $this->get('/login');

        $response->assertRedirect(route('auth.choose'));
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }

    public function test_auth_choose_page_is_noindexed(): void
    {
        $response = $this->get('/auth/login');

        $response->assertOk();
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }

    public function test_magic_login_without_token_redirect_is_noindexed(): void
    {
        $response = $this->get('/auth/magic');

        $response->assertRedirect('/');
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }

    public function test_telegram_auth_status_check_is_noindexed(): void
    {
        $response = $this->getJson('/auth/telegram/check/unknown-token');

        $response->assertOk();
        $response->assertJson(['status' => 'expired']);
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }

    // ─── Токенизированные маршруты на фикстурах: noindex, Location неизменен ───

    public function test_tracking_link_redirect_is_noindexed(): void
    {
        $master = $this->createWidgetMaster();
        TrackingLink::factory()->forMaster($master)->create(['token' => 'seo-token-1', 'is_active' => true]);

        $response = $this->get('/r/seo-token-1');

        $response->assertRedirect(route('booking.widget', $master->master_slug));
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }

    public function test_unknown_tracking_token_404_is_noindexed(): void
    {
        $response = $this->get('/r/definitely-unknown');

        $response->assertNotFound();
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }

    public function test_client_auth_token_redirect_is_noindexed(): void
    {
        Client::factory()->create(['auth_token' => 'seo-client-token']);

        $response = $this->get('/client/auth/seo-client-token');

        $response->assertRedirect(route('client.profile'));
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }

    public function test_expired_client_auth_token_redirect_is_noindexed(): void
    {
        $response = $this->get('/client/auth/no-such-token');

        $response->assertRedirect(route('client.login.expired'));
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }

    // ─── API и health: noindex, статусы неизменны ───

    public function test_api_ping_without_init_data_is_noindexed(): void
    {
        $response = $this->getJson('/api/miniapp/ping');

        $response->assertStatus(401);
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }

    public function test_health_endpoint_is_noindexed(): void
    {
        $response = $this->get('/up');

        $response->assertOk();
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }

    // ─── robots.txt и sitemap.xml: содержимое файлов ───

    public function test_robots_txt_allows_crawl_and_links_sitemap(): void
    {
        $content = file_get_contents(public_path('robots.txt'));

        $this->assertStringNotContainsString('Disallow: /max-app', $content);
        $this->assertStringContainsString('User-agent: *', $content);
        $this->assertStringContainsString('Allow: /', $content);
        $this->assertStringContainsString('Sitemap: https://irsi-app.ru/sitemap.xml', $content);
    }

    public function test_sitemap_contains_single_home_url_without_lastmod(): void
    {
        $this->assertFileExists(public_path('sitemap.xml'));

        $content = file_get_contents(public_path('sitemap.xml'));

        $this->assertNotFalse(simplexml_load_string($content));
        $this->assertSame(1, substr_count($content, '<url>'));
        $this->assertStringContainsString('<loc>https://irsi-app.ru/</loc>', $content);
        $this->assertStringNotContainsString('<lastmod', $content);
    }
}
