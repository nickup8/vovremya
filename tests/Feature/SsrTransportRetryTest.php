<?php

namespace Tests\Feature;

use Illuminate\Foundation\Vite;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Billing\MakesFailingHttpTransport;
use Tests\TestCase;

/**
 * Transport-level retry policy for the Inertia SSR endpoints.
 *
 * Every case drives the REAL Guzzle retry middleware registered by
 * AppServiceProvider with an async rejected promise (Guzzle
 * ConnectException) — see MakesFailingHttpTransport for why a plain
 * Http::fake(Http::failedConnection()) cannot prove attempt counts.
 */
class SsrTransportRetryTest extends TestCase
{
    use MakesFailingHttpTransport;

    /**
     * The production retry budget: decider allows retries 0..4, so a retried
     * request is dispatched 6 times in total.
     */
    private const RETRY_BUDGET_ATTEMPTS = 6;

    private const SSR_BASE = 'http://127.0.0.1:13714';

    /** @var list<string> Temp hot files created by this test. */
    private array $createdHotFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->createdHotFiles as $hotFile) {
            if (is_file($hotFile)) {
                unlink($hotFile);
            }
        }

        parent::tearDown();
    }

    // ── SSR endpoints: exactly one HTTP attempt ──

    public function test_ssr_render_transport_failure_is_attempted_exactly_once(): void
    {
        $this->failTransportOnPaths('/render');

        $caught = null;

        try {
            Http::post(self::SSR_BASE.'/render', ['component' => 'welcome-v2']);
        } catch (ConnectionException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(ConnectionException::class, $caught, 'A dead SSR node must surface as a connection failure');
        $this->assertSame(1, $this->attemptsForUrl(self::SSR_BASE.'/render'), 'SSR dispatch must be single-attempt');
    }

    public function test_ssr_health_check_transport_failure_is_attempted_exactly_once(): void
    {
        $this->failTransportOnPaths('/health');

        $caught = null;

        try {
            Http::get(self::SSR_BASE.'/health');
        } catch (ConnectionException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(ConnectionException::class, $caught);
        $this->assertSame(1, $this->attemptsForUrl(self::SSR_BASE.'/health'), 'SSR health check must be single-attempt');
    }

    public function test_dev_hot_ssr_endpoint_is_attempted_exactly_once(): void
    {
        // Фактический dev-адрес: public/hot владеет Vite-девсервер, и
        // HttpGateway диспатчит SSR на {hot}/__inertia_ssr — тот же
        // retry-middleware, что и для production-URL.
        $this->useHotFile('http://localhost:5173');
        $this->failTransportOnPaths('/__inertia_ssr');

        $caught = null;

        try {
            Http::post('http://localhost:5173/__inertia_ssr', ['component' => 'welcome-v2']);
        } catch (ConnectionException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(ConnectionException::class, $caught);
        $this->assertSame(1, $this->attemptsForUrl('http://localhost:5173/__inertia_ssr'), 'Dev SSR dispatch must be single-attempt');
    }

    // ── Look-alike URLs of other nodes keep the retry budget ──

    public function test_similar_urls_of_other_ssr_nodes_keep_transport_retries(): void
    {
        $this->failTransportOnPaths('/render');

        $urls = [
            // Другой порт на том же хосте.
            'http://127.0.0.1:13715/render',
            // Другой хост.
            'http://localhost:13714/render',
            // Префикс-ловушка бывшего str_starts_with: 'http://127.0.0.1:13714'
            // — префикс строки с userinfo чужого хоста (порт 13714x > 65535
            // Guzzle не принимает вовсе).
            'http://127.0.0.1:13714@evil.example.com/render',
            // Другая схема.
            'https://127.0.0.1:13714/render',
        ];

        foreach ($urls as $url) {
            $this->postIgnoringFailure($url);
        }

        foreach ($urls as $url) {
            $this->assertSame(
                self::RETRY_BUDGET_ATTEMPTS,
                $this->attemptsForUrl($url),
                "Another node's look-alike URL must keep the retry budget: {$url}",
            );
        }
    }

    public function test_foreign_path_on_ssr_origin_keeps_transport_retries(): void
    {
        $this->failTransportOnPaths('/render', '/other');

        // Бывший префиксный матчинг глушил ретраи для ЛЮБОГО пути на origin
        // SSR; точное сравнение пути этого больше не делает.
        $this->postIgnoringFailure(self::SSR_BASE.'/other');

        $this->assertSame(
            self::RETRY_BUDGET_ATTEMPTS,
            $this->attemptsForUrl(self::SSR_BASE.'/other'),
            'A foreign path on the SSR origin must keep the retry budget',
        );
    }

    public function test_dev_hot_endpoint_on_another_port_keeps_transport_retries(): void
    {
        $this->useHotFile('http://localhost:5173');
        $this->failTransportOnPaths('/__inertia_ssr');

        $this->postIgnoringFailure('http://localhost:5174/__inertia_ssr');

        $this->assertSame(
            self::RETRY_BUDGET_ATTEMPTS,
            $this->attemptsForUrl('http://localhost:5174/__inertia_ssr'),
            'A look-alike dev SSR port must keep the retry budget',
        );
    }

    public function test_ssr_url_effective_port_is_matched_exactly(): void
    {
        config(['inertia.ssr.url' => 'https://ssr.example.test']);
        $this->failTransportOnPaths('/render');

        // Явный default-порт :443 — тот же effective origin: psr7 убирает
        // ':443' ещё до retry-слоя, configured 'https://host' матчит его.
        $caught = null;

        try {
            Http::post('https://ssr.example.test:443/render', []);
        } catch (ConnectionException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(ConnectionException::class, $caught);
        $this->assertSame(1, $this->attemptsForUrl('https://ssr.example.test/render'), 'The scheme-default port must be the same origin');

        $this->postIgnoringFailure('https://ssr.example.test:8443/render');
        $this->assertSame(
            self::RETRY_BUDGET_ATTEMPTS,
            $this->attemptsForUrl('https://ssr.example.test:8443/render'),
            'A non-default port must be another origin',
        );
    }

    // ── Everything else keeps the transport retries ──

    public function test_normal_external_request_keeps_transport_retries(): void
    {
        $this->failTransportOnPaths('/bot123/getMe');

        $this->postIgnoringFailure('https://api.telegram.org/bot123/getMe');

        $this->assertSame(
            self::RETRY_BUDGET_ATTEMPTS,
            $this->attemptsForUrl('https://api.telegram.org/bot123/getMe'),
            'A normal external request must keep the original retry budget',
        );
    }

    public function test_empty_ssr_url_does_not_disable_retries(): void
    {
        config(['inertia.ssr.url' => '']);
        $this->failTransportOnPaths('/render');

        $this->postIgnoringFailure(self::SSR_BASE.'/render');

        $this->assertSame(
            self::RETRY_BUDGET_ATTEMPTS,
            $this->attemptsForUrl(self::SSR_BASE.'/render'),
            'An empty inertia.ssr.url must not switch retries off',
        );
    }

    public function test_invalid_ssr_url_does_not_disable_retries(): void
    {
        config(['inertia.ssr.url' => 'not-a-url']);
        $this->failTransportOnPaths('/render');

        $this->postIgnoringFailure(self::SSR_BASE.'/render');

        $this->assertSame(
            self::RETRY_BUDGET_ATTEMPTS,
            $this->attemptsForUrl(self::SSR_BASE.'/render'),
            'An unparsable inertia.ssr.url must not switch retries off',
        );
    }

    /**
     * Point Vite at a temp hot file so the dev-endpoint cases do not depend
     * on the real public/hot (stale-hot trap) and never touch it.
     */
    private function useHotFile(string $url): void
    {
        $hotFile = storage_path('framework/ssr-hot-test-'.uniqid('', true));
        file_put_contents($hotFile, $url);
        $this->createdHotFiles[] = $hotFile;

        $this->app->instance(Vite::class, (new Vite)->useHotFile($hotFile));
    }

    private function postIgnoringFailure(string $url): void
    {
        try {
            Http::post($url, []);
        } catch (ConnectionException) {
            // Expected: the fake transport rejects every attempt; the attempt
            // count is what the assertions observe.
        }
    }
}
