<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HelpPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected(): void
    {
        $response = $this->get('/admin/help');

        $response->assertRedirect('/login');
    }

    public function test_authenticated_master_can_view_help_page(): void
    {
        $user = User::factory()->create([
            'is_master' => true,
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);

        $response = $this->actingAs($user)->get('/admin/help');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('admin/help')
        );
    }
}
