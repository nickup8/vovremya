<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ANALYTICS-01: асинхронный счётчик Яндекс Метрики на гостевой главной /.
 *
 * Счётчик подключается только на welcome-v2 при положительном
 * целочисленном YANDEX_METRIKA_COUNTER_ID (config/analytics.php);
 * остальные страницы — вход, виджет записи, кабинеты — счётчика не видят
 * при любом значении.
 */
class YandexMetrikaTest extends TestCase
{
    use RefreshDatabase;

    private const COUNTER_ID = '99999999';

    private const TAG_JS = 'https://mc.yandex.ru/metrika/tag.js';

    // ─── Главная: счётчик подключён ───

    public function test_home_loads_async_metrika_when_counter_id_configured(): void
    {
        $this->withCounter();

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee(self::TAG_JS, false);
        $response->assertSee('ym('.self::COUNTER_ID.', "init"', false);
        $response->assertSee('https://mc.yandex.ru/watch/'.self::COUNTER_ID, false);
        // Вебвизор, карта кликов и автоматический трекинг ссылок выключены явно.
        $response->assertSee('webvisor: false', false);
        $response->assertSee('trackLinks: false', false);
        $response->assertSee('clickmap: false', false);

        // noscript-пиксель лежит в <body> под тем же условием, что и скрипт.
        $content = $response->getContent();
        $this->assertGreaterThan(
            strpos($content, '<body'),
            strpos($content, 'https://mc.yandex.ru/watch/'.self::COUNTER_ID),
            'noscript-пиксель должен находиться в <body>',
        );
    }

    public function test_home_renders_exactly_one_snippet_and_keeps_utm(): void
    {
        $this->withCounter();

        $response = $this->get('/?utm_source=telegram&utm_campaign=an01');

        $response->assertOk();

        $content = $response->getContent();
        // Один snippet в HTML — один init и один просмотр при загрузке
        // (дубли при SSR/гидратации исключены: сниппет вне React-дерева).
        $this->assertSame(1, substr_count($content, self::TAG_JS));
        $this->assertSame(1, substr_count($content, 'ym('.self::COUNTER_ID.', "init"'));

        // Счётчик не переписывает адрес: UTM-метки остаются в URL страницы.
        $response->assertSee('utm_source=telegram', false);
        $response->assertSee('utm_campaign=an01', false);
    }

    // ─── Пустой или некорректный номер: счётчик выключен ───

    public function test_home_has_no_metrika_when_counter_id_empty(): void
    {
        config(['analytics.yandex_metrika_counter_id' => '']);

        $response = $this->get('/?utm_source=telegram');

        $response->assertOk();
        $this->assertStringNotContainsString('mc.yandex.ru', $response->getContent());
    }

    public function test_home_has_no_metrika_when_counter_id_not_set(): void
    {
        config(['analytics.yandex_metrika_counter_id' => null]);

        $response = $this->get('/');

        $response->assertOk();
        $this->assertStringNotContainsString('mc.yandex.ru', $response->getContent());
    }

    public function test_home_has_no_metrika_when_counter_id_invalid(): void
    {
        // Счётчик включается только положительным целым ID:
        // строки с мусором, 0, отрицательные и булевы значения — выключен.
        foreach (['123abc', 'not-a-counter', '0', '-1', 0, true] as $invalid) {
            config(['analytics.yandex_metrika_counter_id' => $invalid]);

            $response = $this->get('/');

            $response->assertOk();
            $this->assertStringNotContainsString(
                'mc.yandex.ru',
                $response->getContent(),
                'ID '.var_export($invalid, true).' должен оставлять счётчик выключенным.',
            );
        }
    }

    public function test_home_accepts_positive_integer_counter_id(): void
    {
        config(['analytics.yandex_metrika_counter_id' => 99999999]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('ym(99999999, "init"', false);
        $response->assertSee('https://mc.yandex.ru/watch/99999999', false);
    }

    // ─── Исключённые страницы: вход, виджет записи, кабинеты, правовые ───

    public function test_login_pages_have_no_metrika(): void
    {
        $this->withCounter();

        $this->get('/login')->assertRedirect(route('auth.choose'));

        $choose = $this->get('/auth/login');
        $choose->assertOk();
        $this->assertStringNotContainsString('mc.yandex.ru', $choose->getContent());

        $magic = $this->get('/auth/magic');
        $magic->assertRedirect('/');
        $this->assertStringNotContainsString('mc.yandex.ru', $magic->getContent());
    }

    public function test_booking_widget_has_no_metrika(): void
    {
        $this->withCounter();
        $master = $this->createWidgetMaster();

        $response = $this->get("/book/{$master->master_slug}");

        $response->assertOk();
        $this->assertStringNotContainsString('mc.yandex.ru', $response->getContent());
    }

    public function test_admin_cabinet_has_no_metrika(): void
    {
        $this->withCounter();
        $user = User::factory()->create([
            'is_master' => true,
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);

        $response = $this->actingAs($user)->get('/admin/calendar');

        $response->assertOk();
        $this->assertStringNotContainsString('mc.yandex.ru', $response->getContent());
    }

    public function test_client_cabinet_has_no_metrika(): void
    {
        $this->withCounter();

        $response = $this->actingAs(Client::factory()->create(), 'client')->get('/client/my-bookings');

        $response->assertOk();
        $this->assertStringNotContainsString('mc.yandex.ru', $response->getContent());
    }

    public function test_legal_pages_have_no_metrika(): void
    {
        $this->withCounter();

        foreach (['/privacy', '/offer'] as $uri) {
            $response = $this->get($uri);

            $response->assertOk();
            $this->assertStringNotContainsString('mc.yandex.ru', $response->getContent(), "Страница {$uri} не должна содержать счётчик.");
        }
    }

    public function test_authenticated_home_redirect_has_no_metrika(): void
    {
        $this->withCounter();

        $response = $this->actingAs(User::factory()->master()->create())->get('/');

        $response->assertRedirect(route('admin.calendar'));
        $this->assertStringNotContainsString('mc.yandex.ru', $response->getContent());
    }

    // ─── Canonical главной: без UTM, константный адрес ───

    public function test_home_canonical_stays_without_utm(): void
    {
        // Canonical отдаёт React <Head> через SSR-рендер главной; в этом
        // тесте SSR выключен, поэтому фиксируем источник компонента:
        // canonical всегда HOME_URL без query и UTM-меток.
        $source = file_get_contents(resource_path('js/pages/welcome-v2.tsx'));

        $this->assertStringContainsString("const HOME_URL = 'https://irsi-app.ru/';", $source);
        $this->assertStringContainsString('<link rel="canonical" href={HOME_URL} />', $source);
    }

    // ─── Helpers ───

    private function withCounter(): void
    {
        config(['analytics.yandex_metrika_counter_id' => self::COUNTER_ID]);
    }

    private function createWidgetMaster(): User
    {
        $master = User::factory()->master()->create([
            'workspace_id' => null,
            'master_slug' => 'metrika-test-master',
            'is_service_provider' => true,
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);

        $ws = Workspace::create(['name' => 'Metrika Test WS', 'owner_id' => $master->id]);
        $catalog = ServiceCatalog::create(['workspace_id' => $ws->id, 'title' => 'Стрижка', 'base_price' => 1000, 'base_duration' => 60]);
        MasterService::create(['master_id' => $master->id, 'catalog_id' => $catalog->id, 'is_active' => true]);

        return $master;
    }
}
