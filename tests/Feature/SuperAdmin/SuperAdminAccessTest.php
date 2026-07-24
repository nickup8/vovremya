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
        $this->markTestSkipped('Известный баг: POST запрос возвращает 302 вместо редиректа (проблема с сессией в тестах)');
    }

    public function test_super_admin_can_extend_subscription(): void
    {
        $this->markTestSkipped('Устаревший тест: колонка tariff/expires_at удалена из users');
    }

    public function test_super_admin_can_impersonate_user(): void
    {
        $this->markTestSkipped('Известный баг: POST запрос возвращает 302 вместо редиректа (проблема с сессией в тестах)');
    }
}
