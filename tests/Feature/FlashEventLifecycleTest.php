<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Real HTTP/Inertia lifecycle for flash + flash.id:
 * operation → redirect with session flash → next request resolves one id →
 * subsequent request without new event yields empty flash → partial reload
 * of appointments does not mint a new id for stale flash.
 */
class FlashEventLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function resolveFlashProps(): array
    {
        $middleware = app(HandleInertiaRequests::class);
        $shared = $middleware->share(request());

        $flash = $shared['flash'];
        $resolved = [];

        foreach ($flash as $key => $value) {
            $resolved[$key] = is_callable($value) ? $value() : $value;
        }

        return $resolved;
    }

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

    private function clearFlashBag(): void
    {
        session()->forget(['success', 'error', 'message', 'flash_event_id']);
        session()->put('_flash.old', []);
        session()->put('_flash.new', []);
    }

    public function test_catalog_store_redirect_sets_success_flash_for_follow_up_request(): void
    {
        $master = $this->master();
        $this->actingAs($master);

        $response = $this->post('/admin/catalog', [
            'title' => 'Жизненный цикл',
            'base_price' => 1000,
            'base_duration' => 60,
            'is_active' => true,
        ], [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $first = $this->resolveFlashProps();
        $this->assertNotEmpty($first['success']);
        $this->assertIsString($first['id']);
        $this->assertNotEmpty($first['id']);
        $this->assertTrue(Str::isUuid($first['id']));

        // Re-resolving share in the same request must reuse the same id
        $again = $this->resolveFlashProps();
        $this->assertSame($first['id'], $again['id']);
        $this->assertSame($first['success'], $again['success']);
    }

    public function test_sequential_operations_get_distinct_flash_ids_for_same_text(): void
    {
        $master = $this->master();
        $this->actingAs($master);

        // First operation — SettingsController:178 updateBooking
        $this->put('/admin/settings/booking', [
            'slot_interval' => 30,
            'cancellation_deadline_hours' => 24,
            'autofill_enabled' => false,
        ], [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
        ])->assertRedirect();

        $firstFlash = $this->resolveFlashProps();
        $this->assertSame('Настройки записи сохранены', $firstFlash['success']);
        $this->assertNotEmpty($firstFlash['id']);

        $this->clearFlashBag();

        // Second operation, identical text
        $this->put('/admin/settings/booking', [
            'slot_interval' => 30,
            'cancellation_deadline_hours' => 24,
            'autofill_enabled' => false,
        ], [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
        ])->assertRedirect();

        $secondFlash = $this->resolveFlashProps();
        $this->assertSame('Настройки записи сохранены', $secondFlash['success']);
        $this->assertNotEmpty($secondFlash['id']);
        $this->assertNotSame($firstFlash['id'], $secondFlash['id']);
    }

    public function test_next_request_without_new_event_has_empty_flash_and_null_id(): void
    {
        $master = $this->master();
        $this->actingAs($master);

        // SettingsController:366 updateWorkingHours
        $this->put('/admin/working-hours', [
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
        ])->assertRedirect();

        $withFlash = $this->resolveFlashProps();
        $this->assertNotEmpty($withFlash['success']);
        $this->assertNotEmpty($withFlash['id']);

        $this->clearFlashBag();

        $next = $this->resolveFlashProps();
        $this->assertNull($next['success']);
        $this->assertNull($next['error']);
        $this->assertNull($next['message']);
        $this->assertNull($next['id']);
    }

    public function test_partial_reload_appointments_does_not_create_new_flash_id(): void
    {
        $master = $this->master();
        $this->actingAs($master);

        $this->put('/admin/settings/booking', [
            'slot_interval' => 15,
            'cancellation_deadline_hours' => 12,
            'autofill_enabled' => false,
        ])->assertRedirect();

        $delivered = $this->resolveFlashProps();
        $this->assertNotEmpty($delivered['id']);

        // Flash consumed on delivery request. Partial reload of appointments
        // must not mint a new id from empty flash.
        $this->clearFlashBag();

        // Seed the correct Inertia version (mismatch → 409 carries the version)
        $probe = $this->get('/admin/calendar', ['X-Inertia' => 'true']);
        $version = $probe->headers->get('X-Inertia-Version')
            ?? app(HandleInertiaRequests::class)->version(request());
        $this->assertNotEmpty($version);

        $partial = $this->get('/admin/calendar', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
            'X-Inertia-Partial-Component' => 'admin/calendar',
            'X-Inertia-Partial-Data' => 'appointments',
        ]);

        $partial->assertOk();
        $this->assertNull($this->resolveFlashProps()['id']);
    }

    public function test_uuid_matches_fresh_event_not_reemission(): void
    {
        $middleware = app(HandleInertiaRequests::class);
        $method = new \ReflectionMethod(HandleInertiaRequests::class, 'flashEventId');
        $method->setAccessible(true);

        $storeA = new Store('a', new ArraySessionHandler(120));
        $storeA->start();
        $storeA->flash('success', 'Сохранено');
        $reqA = request()->duplicate();
        $reqA->setLaravelSession($storeA);
        $idA = $method->invoke($middleware, $reqA);

        $storeB = new Store('b', new ArraySessionHandler(120));
        $storeB->start();
        $storeB->flash('success', 'Сохранено');
        $reqB = request()->duplicate();
        $reqB->setLaravelSession($storeB);
        $idB = $method->invoke($middleware, $reqB);

        $this->assertTrue(Str::isUuid($idA));
        $this->assertTrue(Str::isUuid($idB));
        $this->assertNotSame($idA, $idB);

        // Same store, second resolve: same event id (not a re-mint)
        $idA2 = $method->invoke($middleware, $reqA);
        $this->assertSame($idA, $idA2);
    }
}
