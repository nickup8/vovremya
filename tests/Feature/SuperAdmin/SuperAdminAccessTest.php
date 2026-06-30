<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_user_cannot_access_super_admin_dashboard(): void
    {
        $user = User::factory()->master()->create(['is_super_admin' => false]);

        $this->actingAs($user);

        $response = $this->get(route('super_admin.dashboard'));

        $response->assertStatus(403);
    }

    public function test_super_admin_can_access_dashboard(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => true]);

        $this->actingAs($admin);

        $response = $this->get(route('super_admin.dashboard'));

        $response->assertOk();
    }

    public function test_super_admin_can_block_user(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => true]);
        $user = User::factory()->master()->create(['is_blocked' => false]);

        $this->actingAs($admin);

        $response = $this->post(route('super_admin.block', $user->id));

        $response->assertRedirect();
        $user->refresh();
        $this->assertTrue($user->is_blocked);
    }

    public function test_super_admin_can_extend_subscription(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => true]);
        $user = User::factory()->master()->create([
            'tariff' => 'free',
            'expires_at' => null,
        ]);

        $this->actingAs($admin);

        $response = $this->post(route('super_admin.extend', $user->id), [
            'days' => 30,
        ]);

        $response->assertRedirect();
        $user->refresh();
        $this->assertNotNull($user->expires_at);
        $this->assertTrue($user->expires_at->isAfter(now()->addDays(29)));
        $this->assertEquals('pro', $user->tariff);
    }

    public function test_super_admin_can_impersonate_user(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => true]);
        $user = User::factory()->master()->create();

        $this->actingAs($admin);

        $response = $this->post(route('super_admin.impersonate', $user->id));

        $response->assertRedirect(route('admin.calendar'));
        $this->assertEquals($user->id, auth()->id());
    }
}
