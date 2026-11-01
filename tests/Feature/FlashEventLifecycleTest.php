<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Real HTTP/Inertia lifecycle for flash + flash.id in one session:
 * operation → GET destination page (Inertia JSON) → flash present with UUID
 * → next GET without operation → empty flash
 * → second operation same text → GET → different UUID.
 */
class FlashEventLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function master(): User
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $workspace = Workspace::create([
            'name' => 'Flash Lifecycle WS',
            'owner_id' => $owner->id,
        ]);

        return User::factory()->master()->create([
            'role' => UserRole::Owner,
            'workspace_id' => $workspace->id,
        ]);
    }

    /**
     * Inertia asset version (manifest hash). Must match what the middleware
     * computes; otherwise GET with X-Inertia returns 409 location redirect.
     */
    private function inertiaVersion(): ?string
    {
        return app(HandleInertiaRequests::class)->version(Request::create('/admin/settings'));
    }

    private function inertiaHeaders(?string $version): array
    {
        $headers = ['X-Inertia' => 'true'];

        if ($version !== null) {
            $headers['X-Inertia-Version'] = $version;
        }

        return $headers;
    }

    private function inertiaGet(string $uri, ?string $version): TestResponse
    {
        $response = $this->get($uri, $this->inertiaHeaders($version));
        $response->assertOk();

        return $response;
    }

    private function flashFromJson(TestResponse $response): array
    {
        $page = $response->json();
        $this->assertIsArray($page);
        $this->assertArrayHasKey('props', $page);
        $this->assertArrayHasKey('flash', $page['props']);

        $flash = $page['props']['flash'];
        $this->assertIsArray($flash);

        return $flash;
    }

    public function test_full_http_lifecycle_flash_then_empty_then_new_uuid(): void
    {
        $master = $this->master();
        $this->actingAs($master);

        $destination = '/admin/settings';
        $version = $this->inertiaVersion();

        // ── 1. Operation: booking settings save (SettingsController:178) ──
        $this->from($destination)
            ->put('/admin/settings/booking', [
                'slot_interval' => 30,
                'cancellation_deadline_hours' => 24,
                'autofill_enabled' => false,
            ], $this->inertiaHeaders($version))
            ->assertRedirect($destination)
            ->assertSessionHas('success', 'Настройки записи сохранены');

        // ── 2. Follow-up GET: flash delivered in Inertia JSON ──
        $flash1 = $this->flashFromJson($this->inertiaGet($destination, $version));
        $this->assertSame('Настройки записи сохранены', $flash1['success']);
        $this->assertIsString($flash1['id']);
        $this->assertNotEmpty($flash1['id']);
        $this->assertTrue(Str::isUuid($flash1['id']));
        $firstId = $flash1['id'];

        // ── 3. Next GET, no new operation: flash bag aged out ──
        $flash2 = $this->flashFromJson($this->inertiaGet($destination, $version));
        $this->assertNull($flash2['success'] ?? null);
        $this->assertNull($flash2['error'] ?? null);
        $this->assertNull($flash2['message'] ?? null);
        $this->assertNull($flash2['id'] ?? null);

        // ── 4. Second operation, identical text ──
        $this->from($destination)
            ->put('/admin/settings/booking', [
                'slot_interval' => 30,
                'cancellation_deadline_hours' => 24,
                'autofill_enabled' => false,
            ], $this->inertiaHeaders($version))
            ->assertRedirect($destination)
            ->assertSessionHas('success', 'Настройки записи сохранены');

        $flash3 = $this->flashFromJson($this->inertiaGet($destination, $version));
        $this->assertSame('Настройки записи сохранены', $flash3['success']);
        $this->assertIsString($flash3['id']);
        $this->assertNotEmpty($flash3['id']);
        $this->assertNotSame($firstId, $flash3['id']);
        $this->assertTrue(Str::isUuid($flash3['id']));
    }

    public function test_working_hours_operation_delivers_flash_then_empties(): void
    {
        $master = $this->master();
        $this->actingAs($master);

        $destination = '/admin/settings';
        $version = $this->inertiaVersion();

        $this->from($destination)
            ->put('/admin/working-hours', [
                'working_hours' => [
                    [
                        'day_of_week' => 1,
                        'is_working' => true,
                        'start_time' => '09:00',
                        'end_time' => '18:00',
                        'break_start_time' => null,
                        'break_end_time' => null,
                    ],
                ],
            ], $this->inertiaHeaders($version))
            ->assertRedirect($destination)
            ->assertSessionHas('success');

        $flash1 = $this->flashFromJson($this->inertiaGet($destination, $version));
        $this->assertSame('График работы обновлён', $flash1['success']);
        $this->assertTrue(Str::isUuid((string) $flash1['id']));

        // Aging happens on the delivery GET; next GET has empty flash
        $flash2 = $this->flashFromJson($this->inertiaGet($destination, $version));
        $this->assertNull($flash2['success'] ?? null);
        $this->assertNull($flash2['id'] ?? null);
    }

    public function test_partial_reload_appointments_excludes_flash_from_json(): void
    {
        $master = $this->master();
        $this->actingAs($master);

        // Deliver flash once via booking save
        $this->from('/admin/settings')
            ->put('/admin/settings/booking', [
                'slot_interval' => 15,
                'cancellation_deadline_hours' => 12,
                'autofill_enabled' => false,
            ])->assertRedirect('/admin/settings');

        $version = $this->inertiaVersion();

        $partialHeaders = $this->inertiaHeaders($version);
        $partialHeaders['X-Inertia-Partial-Component'] = 'admin/calendar';
        $partialHeaders['X-Inertia-Partial-Data'] = 'appointments';
        $partial = $this->get('/admin/calendar', $partialHeaders);

        $partial->assertOk();

        $json = $partial->json();
        $this->assertIsArray($json);
        $this->assertArrayHasKey('props', $json);

        // Partial response includes appointments key, must not re-emit flash
        $this->assertArrayHasKey('appointments', $json['props']);
        $this->assertArrayNotHasKey('flash', $json['props']);
    }

    public function test_catalog_store_http_cycle_delivers_uuid_flash(): void
    {
        $master = $this->master();
        $this->actingAs($master);

        $destination = '/admin/catalog';
        $version = $this->inertiaVersion();

        $this->from($destination)
            ->post('/admin/catalog', [
                'title' => 'Жизненный цикл',
                'base_price' => 1000,
                'base_duration' => 60,
                'is_active' => true,
            ], $this->inertiaHeaders($version))
            ->assertRedirect($destination)
            ->assertSessionHas('success');

        $flash1 = $this->flashFromJson($this->inertiaGet($destination, $version));
        $this->assertIsString($flash1['success']);
        $this->assertStringContainsString('добавлена', $flash1['success']);
        $this->assertTrue(Str::isUuid((string) $flash1['id']));

        $flash2 = $this->flashFromJson($this->inertiaGet($destination, $version));
        $this->assertNull($flash2['id'] ?? null);
    }
}
