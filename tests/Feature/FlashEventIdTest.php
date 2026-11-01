<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class FlashEventIdTest extends TestCase
{
    private function makeRequest(?array $flashData = null): Request
    {
        Config::set('session.driver', 'array');
        Config::set('session.lifetime', 120);

        $store = new Store('test', new ArraySessionHandler(120));
        $store->start();

        if ($flashData !== null) {
            foreach ($flashData as $key => $value) {
                $store->flash($key, $value);
            }
        }

        $request = Request::create('/admin/calendar', 'GET');
        $request->setLaravelSession($store);

        return $request;
    }

    private function invokeFlashEventId(Request $request): ?string
    {
        $middleware = app(HandleInertiaRequests::class);
        $method = new \ReflectionMethod(HandleInertiaRequests::class, 'flashEventId');
        $method->setAccessible(true);

        return $method->invoke($middleware, $request);
    }

    public function test_flash_id_is_null_without_flash_content(): void
    {
        $request = $this->makeRequest(null);

        $this->assertNull($this->invokeFlashEventId($request));
    }

    public function test_flash_id_is_unique_per_response_when_flash_present(): void
    {
        $firstRequest = $this->makeRequest(['success' => 'Услуга добавлена']);
        $first = $this->invokeFlashEventId($firstRequest);
        $this->assertNotNull($first);

        // New response after flash aged out — no id
        $emptyRequest = $this->makeRequest(null);
        $this->assertNull($this->invokeFlashEventId($emptyRequest));

        // Sequential operation, same text — different id
        $secondRequest = $this->makeRequest(['success' => 'Услуга добавлена']);
        $second = $this->invokeFlashEventId($secondRequest);
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second);
    }

    public function test_inertia_share_exposes_flash_id_with_existing_fields(): void
    {
        $request = $this->makeRequest(['success' => 'График работы обновлён']);

        $middleware = app(HandleInertiaRequests::class);
        $shared = $middleware->share($request);

        $flash = $shared['flash'];
        $resolved = [];
        foreach ($flash as $key => $value) {
            $resolved[$key] = is_callable($value) ? $value() : $value;
        }

        $this->assertSame('График работы обновлён', $resolved['success']);
        $this->assertArrayHasKey('error', $resolved);
        $this->assertArrayHasKey('message', $resolved);
        $this->assertArrayHasKey('id', $resolved);
        $this->assertIsString($resolved['id']);
        $this->assertNotEmpty($resolved['id']);
    }

    public function test_error_flash_also_gets_id(): void
    {
        $request = $this->makeRequest(['error' => 'Нет доступа']);

        $id = $this->invokeFlashEventId($request);

        $this->assertIsString($id);
        $this->assertNotEmpty($id);
    }
}
