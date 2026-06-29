<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimezoneTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    protected function setUp(): void
    {
        parent::setUp();

        $this->master = User::factory()->master()->create();
    }

    public function test_patch_timezone_with_valid_zone_saves_and_confirms(): void
    {
        $response = $this->actingAs($this->master)
            ->patchJson('/admin/settings/timezone', [
                'timezone' => 'Asia/Krasnoyarsk',
            ]);

        $response->assertRedirect();

        $this->master->refresh();

        $this->assertEquals('Asia/Krasnoyarsk', $this->master->getTimezone());
        $this->assertTrue($this->master->isTimezoneConfirmed());
    }

    public function test_patch_timezone_with_invalid_zone_is_rejected(): void
    {
        $response = $this->actingAs($this->master)
            ->patch('/admin/settings/timezone', [
                'timezone' => 'Invalid/Zone',
            ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('timezone');

        $this->master->refresh();

        $this->assertEquals('Europe/Moscow', $this->master->getTimezone());
        $this->assertFalse($this->master->isTimezoneConfirmed());
    }

    public function test_patch_timezone_without_value_is_rejected(): void
    {
        $response = $this->actingAs($this->master)
            ->patch('/admin/settings/timezone', []);

        $response->assertRedirect();
        $response->assertSessionHasErrors('timezone');
    }

    public function test_unauthenticated_user_cannot_update_timezone(): void
    {
        $response = $this->patchJson('/admin/settings/timezone', [
            'timezone' => 'Europe/Moscow',
        ]);

        $response->assertStatus(302);
    }

    public function test_settings_page_passes_timezone_data(): void
    {
        $this->master->setTimezone('Asia/Yakutsk');

        $response = $this->actingAs($this->master)
            ->get('/admin/settings');

        $response->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('admin/settings')
            ->has('profile.timezone')
            ->where('profile.timezone', 'Asia/Yakutsk')
            ->where('profile.timezone_confirmed', true)
        );
    }
}
