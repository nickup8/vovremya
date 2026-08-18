<?php

namespace Tests\Feature;

use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\WorkingHour;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WidgetVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Канон 13.4/13.5: услуга/мастер видны ⟺ master_service.is_active=true И catalog.is_active=true.
     */

    private function createSoloMaster(): User
    {
        return User::factory()->master()->create([
            'workspace_id' => null,
            'master_slug' => 'solo-master',
            'is_service_provider' => true,
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);
    }

    private function attachCatalog(User $master, string $title, bool $catalogActive, bool $msActive): ServiceCatalog
    {
        $workspace = Workspace::where('owner_id', $master->id)->first()
            ?? Workspace::create(['name' => 'Solo WS '.$master->id, 'owner_id' => $master->id]);

        $catalog = ServiceCatalog::create([
            'workspace_id' => $workspace->id,
            'title' => $title,
            'base_price' => 1000,
            'base_duration' => 60,
            'is_active' => $catalogActive,
        ]);

        MasterService::create([
            'master_id' => $master->id,
            'catalog_id' => $catalog->id,
            'is_active' => $msActive,
        ]);

        return $catalog;
    }

    public function test_service_visible_when_both_active(): void
    {
        $master = $this->createSoloMaster();
        $this->attachCatalog($master, 'Маникюр', catalogActive: true, msActive: true);

        $response = $this->get("/book/{$master->master_slug}");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->has('services', 1)
            ->where('services.0.title', 'Маникюр')
        );
    }

    public function test_service_hidden_when_catalog_inactive(): void
    {
        $master = $this->createSoloMaster();
        $this->attachCatalog($master, 'Стрижка', catalogActive: false, msActive: true);

        $this->get("/book/{$master->master_slug}")->assertStatus(404);
    }

    public function test_service_hidden_when_masterservice_inactive(): void
    {
        $master = $this->createSoloMaster();
        $this->attachCatalog($master, 'Стрижка', catalogActive: true, msActive: false);

        $this->get("/book/{$master->master_slug}")->assertStatus(404);
    }

    public function test_only_active_services_listed(): void
    {
        $master = $this->createSoloMaster();
        $this->attachCatalog($master, 'Активная', catalogActive: true, msActive: true);
        $this->attachCatalog($master, 'Скрытая', catalogActive: false, msActive: true);

        $response = $this->get("/book/{$master->master_slug}");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->has('services', 1)
            ->where('services.0.title', 'Активная')
        );
    }

    public function test_store_rejects_hidden_service(): void
    {
        $master = $this->createSoloMaster();
        $catalog = $this->attachCatalog($master, 'Педикюр', catalogActive: true, msActive: true);
        $this->attachCatalog($master, 'Маникюр', catalogActive: true, msActive: true);

        $ms = MasterService::where('master_id', $master->id)->where('catalog_id', $catalog->id)->firstOrFail();

        $dayOfWeek = Carbon::tomorrow('Europe/Moscow')->dayOfWeek;
        WorkingHour::updateOrCreate(
            ['user_id' => $master->id, 'day_of_week' => $dayOfWeek],
            [
                'start_time' => '09:00',
                'end_time' => '18:00',
                'break_start_time' => '13:00',
                'break_end_time' => '14:00',
                'is_working' => true,
            ],
        );

        $payload = [
            'service_id' => $ms->id,
            'date' => Carbon::tomorrow('Europe/Moscow')->toDateString(),
            'time' => '10:00',
            'provider' => 'max',
        ];

        $baseline = $this->postJson("/book/{$master->master_slug}", $payload);
        $baseline->assertOk();
        $this->assertDatabaseCount('appointments', 1);

        $catalog->update(['is_active' => false]);

        $rejected = $this->postJson("/book/{$master->master_slug}", $payload);
        $rejected->assertStatus(422);
        $rejected->assertJsonValidationErrors('service_id');
        $this->assertDatabaseCount('appointments', 1);
    }

}
