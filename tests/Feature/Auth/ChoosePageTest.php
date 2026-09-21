<?php

namespace Tests\Feature\Auth;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ChoosePageTest extends TestCase
{
    public function test_login_page_returns_200(): void
    {
        $response = $this->get(route('auth.choose'));

        $response->assertOk();
    }

    public function test_login_page_renders_choose_component(): void
    {
        $response = $this->get(route('auth.choose'));

        $response->assertInertia(fn (Assert $page) => $page->component('auth/choose'));
    }

    public function test_vk_start_route_still_works(): void
    {
        $response = $this->post(route('auth.vk.start'));

        // Should redirect to VK OAuth URL (302) — confirms route exists
        $response->assertRedirect();
    }

    public function test_telegram_token_endpoint_still_works(): void
    {
        $response = $this->postJson(route('auth.telegram.token'));

        $response->assertOk();
        $response->assertJsonStructure(['token']);
    }
}
