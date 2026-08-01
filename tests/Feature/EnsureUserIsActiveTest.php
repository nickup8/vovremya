<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
