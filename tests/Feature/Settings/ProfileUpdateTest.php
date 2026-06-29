<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('admin.settings'));

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->put(route('admin.settings.update'), [
                'name' => 'Updated Name',
                'phone' => '+79001234567',
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $user->refresh();

        $this->assertSame('Updated Name', $user->name);
        $this->assertSame('+79001234567', $user->phone);
    }

    public function test_profile_requires_name_and_phone()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->put(route('admin.settings.update'), [
                'name' => '',
                'phone' => '',
            ]);

        $response->assertSessionHasErrors(['name', 'phone']);
    }
}
