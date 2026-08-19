<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ActiveServiceGuardTest extends TestCase
{
    use RefreshDatabase;

    private function createSoloWithService(): array
    {
        $master = User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'Solo WS ' . Str::random(6),
            'owner_id' => $master->id,
        ]);
        $master->update(['workspace_id' => $workspace->id, 'role' => \App\Enums\UserRole::Owner]);

        $catalog = ServiceCatalog::create([
            'workspace_id' => $workspace->id,
            'title' => 'Маникюр',
            'base_price' => 1000,
            'base_duration' => 30,
            'is_active' => true,
        ]);

        $ms = MasterService::create([
            'master_id' => $master->id,
            'catalog_id' => $catalog->id,
            'is_active' => true,
        ]);

        return [$master, $workspace, $catalog, $ms];
    }

    public function test_direct_manual_post_with_inactive_catalog_rejected(): void
    {
        [$master, $ws, $catalog, $ms] = $this->createSoloWithService();

        $catalog->update(['is_active' => false]);

        $client = Client::factory()->create([
            'workspace_id' => $ws->id,
        ]);

        $response = $this->actingAs($master)->post('/admin/calendar/appointments', [
            'client_id' => $client->id,
            'service_id' => $ms->id,
            'date' => now()->addDay()->format('Y-m-d'),
            'time' => '10:00',
        ]);

        $response->assertStatus(422);
    }

    public function test_direct_manual_post_with_active_catalog_succeeds(): void
    {
        [$master, $ws, $catalog, $ms] = $this->createSoloWithService();

        $client = Client::factory()->create([
            'workspace_id' => $ws->id,
        ]);

        $response = $this->actingAs($master)->post('/admin/calendar/appointments', [
            'client_id' => $client->id,
            'service_id' => $ms->id,
            'date' => now()->addDay()->format('Y-m-d'),
            'time' => '10:00',
        ]);

        $response->assertRedirect();
    }

    public function test_existing_appointment_unchanged_after_catalog_deactivate(): void
    {
        [$master, $ws, $catalog, $ms] = $this->createSoloWithService();

        $appointment = Appointment::factory()->forMaster($master)->create([
            'master_service_id' => $ms->id,
            'service_name' => 'Маникюр',
            'price' => 1000,
            'duration' => 30,
            'status' => 'booked',
        ]);

        $catalog->update(['is_active' => false]);

        $appointment->refresh();
        $this->assertSame('booked', $appointment->status->value);
        $this->assertSame('Маникюр', $appointment->service_name);
    }
}
