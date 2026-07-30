<?php

namespace Tests\Unit;

use App\Models\User;
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
            'is_bookable' => true,
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
            'is_bookable' => true,
            'is_service_provider' => true,
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'is_master' => true,
            'is_bookable' => true,
            'is_service_provider' => true,
        ]);
    }

    public function test_non_master_has_is_service_provider_false(): void
    {
        $user = User::factory()->create([
            'is_master' => false,
            'is_bookable' => true,
            'is_service_provider' => false,
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'is_master' => false,
            'is_service_provider' => false,
        ]);
    }

    public function test_master_not_bookable_has_is_service_provider_false(): void
    {
        $user = User::factory()->create([
            'is_master' => true,
            'is_bookable' => false,
            'is_service_provider' => false,
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'is_master' => true,
            'is_bookable' => false,
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

    public function test_solo_master_can_be_found_by_slug(): void
    {
        $master = User::factory()->master()->create([
            'workspace_id' => null,
            'is_master' => true,
            'is_bookable' => true,
            'is_service_provider' => true,
            'master_slug' => 'test-solo-slug',
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);

        $found = User::where('master_slug', 'test-solo-slug')
            ->where('is_master', true)
            ->first();

        $this->assertNotNull($found);
        $this->assertTrue($found->is_service_provider);
        $this->assertTrue($found->isSolo());
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
            'is_bookable' => true,
            'is_service_provider' => true,
            'admin_can_see_finance' => true,
        ]);

        $this->assertIsBool($user->is_service_provider);
        $this->assertIsBool($user->admin_can_see_finance);
        $this->assertTrue($user->is_service_provider);
        $this->assertTrue($user->admin_can_see_finance);
    }
}
