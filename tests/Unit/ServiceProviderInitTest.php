<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceProviderInitTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_for_user_sets_service_provider_true_for_master(): void
    {
        $user = User::factory()->master()->create(['workspace_id' => null]);

        app(WorkspaceService::class)->createForUser($user);
        $user->refresh();

        $this->assertTrue($user->is_master);
        $this->assertTrue($user->is_service_provider);
        $this->assertEquals(UserRole::Owner, $user->role);
        $this->assertNotNull($user->workspace_id);
    }

    public function test_create_for_user_sets_service_provider_false_for_non_master(): void
    {
        $user = User::factory()->create(['workspace_id' => null, 'is_master' => false]);

        app(WorkspaceService::class)->createForUser($user);
        $user->refresh();

        $this->assertFalse($user->is_master);
        $this->assertFalse($user->is_service_provider);
        $this->assertEquals(UserRole::Owner, $user->role);
    }

    public function test_providers_count_only_counts_service_providers(): void
    {
        $workspace = Workspace::create(['name' => 'Studio', 'owner_id' => User::factory()->create()->id]);

        User::factory()->master()->create([
            'workspace_id' => $workspace->id,
            'is_service_provider' => true,
        ]);
        User::factory()->master()->create([
            'workspace_id' => $workspace->id,
            'is_service_provider' => false,
        ]);
        User::factory()->create([
            'workspace_id' => $workspace->id,
            'is_master' => true,
            'is_service_provider' => true,
        ]);

        $this->assertEquals(2, $workspace->providersCount());
        $this->assertEquals(3, $workspace->mastersCount());
    }

    public function test_can_add_provider_uses_max_masters_limit(): void
    {
        $workspace = Workspace::create(['name' => 'Studio', 'owner_id' => User::factory()->create()->id]);

        // Workspace without subscription → maxMasters = START_MAX_MASTERS = 1
        $this->assertTrue($workspace->canAddProvider());

        User::factory()->master()->create([
            'workspace_id' => $workspace->id,
            'is_service_provider' => true,
        ]);

        $this->assertFalse($workspace->canAddProvider());
    }

    public function test_solo_master_has_service_provider_true_but_no_limit_block(): void
    {
        $user = User::factory()->master()->create(['workspace_id' => null]);

        app(WorkspaceService::class)->createForUser($user);
        $user->refresh();

        $this->assertTrue($user->is_service_provider);

        // Solo has personal workspace without subscription
        $this->assertTrue($user->isSolo());
        $this->assertEquals(1, $user->workspace->maxMasters());
        $this->assertEquals(1, $user->workspace->providersCount());

        // TariffLimitService is NOT modified — no blocking logic added
        $limitService = app(\App\Services\Billing\TariffLimitService::class);
        $this->assertTrue($limitService->canCreateAppointment($user->workspace));
    }
}
