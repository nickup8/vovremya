<?php

namespace Tests\Feature\ClientMode;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientModeSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_enable_client_mode_sets_session(): void
    {
        $master = User::factory()->master()->create();

        $this->actingAs($master);

        $response = $this->post(route('client_mode.enable'));

        $response->assertRedirect(route('client.bookings'));
        $this->assertTrue(session('is_client_mode'));
    }

    public function test_disable_client_mode_clears_session(): void
    {
        $master = User::factory()->master()->create();

        $this->actingAs($master);
        session(['is_client_mode' => true]);

        $response = $this->post(route('client_mode.disable'));

        $response->assertRedirect(route('admin.calendar'));
        $this->assertNull(session('is_client_mode'));
    }
}
