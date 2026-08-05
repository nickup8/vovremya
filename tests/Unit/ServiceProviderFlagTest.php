<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceProviderFlagTest extends TestCase
{
    use RefreshDatabase;

    private function createRawUser(array $overrides = []): array
    {
        return array_merge([
            'id' => fake()->uuid(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => bcrypt('password'),
            'is_master' => false,
            'is_service_provider' => false,
            'admin_can_see_finance' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    public function test_solo_master_has_is_service_provider_true(): void
    {
        $user = User::factory()->master()->create([
            'workspace_id' => null,
            'is_master' => true,
            'is_service_provider' => true,
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'is_master' => true,
            'is_service_provider' => true,
        ]);
    }

    public function test_non_master_has_is_service_provider_false(): void
    {
        $user = User::factory()->create([
            'is_master' => false,
            'is_service_provider' => false,
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'is_master' => false,
            'is_service_provider' => false,
        ]);
    }

    public function test_admin_can_see_finance_defaults_to_true(): void
    {
        $user = User::factory()->create([
            'admin_can_see_finance' => true,
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'admin_can_see_finance' => true,
        ]);
    }

    public function test_new_fields_are_fillable(): void
    {
        $user = User::factory()->create([
            'is_service_provider' => true,
            'admin_can_see_finance' => false,
        ]);

        $user->update([
            'is_service_provider' => false,
            'admin_can_see_finance' => true,
        ]);

        $user->refresh();

        $this->assertFalse($user->is_service_provider);
        $this->assertTrue($user->admin_can_see_finance);
    }

    public function test_new_fields_are_cast_to_boolean(): void
    {
        $user = User::factory()->master()->create([
            'workspace_id' => null,
            'is_master' => true,
            'is_service_provider' => true,
            'admin_can_see_finance' => true,
        ]);

        $this->assertIsBool($user->is_service_provider);
        $this->assertIsBool($user->admin_can_see_finance);
        $this->assertTrue($user->is_service_provider);
        $this->assertTrue($user->admin_can_see_finance);
    }

    public function test_visible_in_widget_requires_active_master_service(): void
    {
        $master = User::factory()->master()->create([
            'is_master' => true,
            'is_service_provider' => true,
            'master_slug' => 'test-master',
            'role' => UserRole::Master,
        ]);
        $workspace = Workspace::create(['name' => 'Test WS', 'owner_id' => $master->id]);
        $master->update(['workspace_id' => $workspace->id]);

        // Без активной услуги — не видим
        $this->assertEquals(0, User::visibleInWidget()->where('id', $master->id)->count());

        // Создаём каталог + услугу
        $catalog = ServiceCatalog::create([
            'workspace_id' => $workspace->id,
            'title' => 'Стрижка',
            'base_price' => 1000,
            'base_duration' => 30,
        ]);
        MasterService::create([
            'master_id' => $master->id,
            'catalog_id' => $catalog->id,
            'is_active' => true,
        ]);

        // С активной услугой — видим
        $this->assertEquals(1, User::visibleInWidget()->where('id', $master->id)->count());
    }

    public function test_visible_in_widget_excludes_owner_without_is_service_provider(): void
    {
        $owner = User::factory()->master()->create([
            'is_master' => true,
            'is_service_provider' => false,
            'master_slug' => 'test-owner',
            'role' => UserRole::Owner,
        ]);
        $workspace = Workspace::create(['name' => 'Test WS', 'owner_id' => $owner->id]);
        $owner->update(['workspace_id' => $workspace->id]);

        $catalog = ServiceCatalog::create([
            'workspace_id' => $workspace->id,
            'title' => 'Стрижка',
            'base_price' => 1000,
            'base_duration' => 30,
        ]);
        MasterService::create([
            'master_id' => $owner->id,
            'catalog_id' => $catalog->id,
            'is_active' => true,
        ]);

        // Owner без is_service_provider — не видим
        $this->assertEquals(0, User::visibleInWidget()->where('id', $owner->id)->count());

        // Включаем is_service_provider — видим
        $owner->update(['is_service_provider' => true]);
        $this->assertEquals(1, User::visibleInWidget()->where('id', $owner->id)->count());
    }

    public function test_visible_in_widget_excludes_master_without_slug(): void
    {
        $master = User::factory()->master()->create([
            'is_master' => true,
            'is_service_provider' => true,
            'master_slug' => null,
            'role' => UserRole::Master,
        ]);
        $workspace = Workspace::create(['name' => 'Test WS', 'owner_id' => $master->id]);
        $master->update(['workspace_id' => $workspace->id]);

        $catalog = ServiceCatalog::create([
            'workspace_id' => $workspace->id,
            'title' => 'Стрижка',
            'base_price' => 1000,
            'base_duration' => 30,
        ]);
        MasterService::create([
            'master_id' => $master->id,
            'catalog_id' => $catalog->id,
            'is_active' => true,
        ]);

        // Без slug — не видим
        $this->assertEquals(0, User::visibleInWidget()->where('id', $master->id)->count());
    }
}
