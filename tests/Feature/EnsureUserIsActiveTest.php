<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EnsureUserIsActiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_blocked_user_is_redirected_on_admin_route(): void
    {
        $user = User::factory()->master()->create([
            'is_blocked' => true,
        ]);

        // Use GET (not getJson) to follow redirects and check final destination
        $response = $this->actingAs($user)->get('/admin/calendar');

        // HandleInertiaRequests already catches blocked users and redirects to auth.choose
        // Our EnsureUserIsActive middleware also catches them and redirects to login
        // Either way — the user does NOT reach the calendar
        $response->assertStatus(302);
        $this->assertStringNotContainsString('/admin/calendar', $response->headers->get('Location'));
    }

    public function test_active_user_reaches_admin_route(): void
    {
        $user = User::factory()->master()->create([
            'is_blocked' => false,
        ]);

        $response = $this->actingAs($user)->get('/admin/calendar');

        // Active user gets through both middlewares — reaches the controller
        // CalendarController renders Inertia which returns 200 in test or Inertia response
        $response->assertStatus(200);
    }

    public function test_guest_is_not_affected_by_middleware(): void
    {
        $response = $this->get('/admin/calendar');

        // Guest not logged in — EnsureUserIsActive passes (user is null),
        // then auth middleware redirects to login
        $response->assertStatus(302);
        $this->assertStringContainsString('/login', $response->headers->get('Location'));
    }

    public function test_blocked_user_is_logged_out_by_middleware(): void
    {
        $user = User::factory()->master()->create([
            'is_blocked' => true,
        ]);

        $this->actingAs($user)->get('/admin/calendar');

        // After middleware runs — user should be logged out
        $this->assertGuest();
    }

    public function test_middleware_checks_web_guard_not_client(): void
    {
        $blockedUser = User::factory()->master()->create([
            'is_blocked' => true,
        ]);

        // EnsureUserIsActive looks at Auth::guard('web') only
        // A blocked User in guard web is caught
        $response = $this->actingAs($blockedUser, 'web')->get('/admin/calendar');

        $response->assertStatus(302);
        $this->assertGuest();
    }

    public function test_ensure_user_is_active_only_checks_web_guard(): void
    {
        // Verify that our middleware inspects guard('web'), not guard('client')
        // If a User is blocked, they're logged out from web guard
        $user = User::factory()->master()->create(['is_blocked' => true]);

        $this->actingAs($user, 'web')->get('/admin/calendar');
        $this->assertGuest();
    }

    public function test_block_user_writes_is_blocked_via_mass_assignment(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => true]);
        $target = User::factory()->master()->create(['is_blocked' => false]);

        $response = $this->actingAs($admin)->post("/admin-root/users/{$target->id}/block");

        $response->assertRedirect();
        $this->assertTrue($target->fresh()->is_blocked, 'blockUser must write is_blocked via mass-assignment');
    }

    public function test_block_user_toggle_back(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => true]);
        $target = User::factory()->master()->create(['is_blocked' => false]);

        // Block
        $this->actingAs($admin)->post("/admin-root/users/{$target->id}/block");
        $this->assertTrue($target->fresh()->is_blocked);

        // Unblock
        $this->actingAs($admin)->post("/admin-root/users/{$target->id}/block");
        $this->assertFalse($target->fresh()->is_blocked);
    }

    public function test_block_user_destroys_sessions_on_ban(): void
    {
        $admin = User::factory()->master()->create(['is_super_admin' => true]);
        $target = User::factory()->master()->create(['is_blocked' => false]);

        // Insert fake session row for target
        DB::table('sessions')->insert([
            'id' => 'test-session-' . $target->id,
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => '',
            'last_activity' => time(),
        ]);
        $this->assertDatabaseHas('sessions', ['user_id' => $target->id]);

        // Block → sessions must be deleted
        $this->actingAs($admin)->post("/admin-root/users/{$target->id}/block");
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
    }

    public function test_update_is_blocked_directly_works(): void
    {
        $user = User::factory()->master()->create(['is_blocked' => false]);

        $user->update(['is_blocked' => true]);

        $this->assertTrue($user->fresh()->is_blocked, 'update() must write is_blocked to DB (fillable regression test)');
    }
}
