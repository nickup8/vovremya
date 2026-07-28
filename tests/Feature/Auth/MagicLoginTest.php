<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MagicLoginTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->master = User::factory()->master()->create([
            'is_master' => true,
        ]);

        $this->client = User::factory()->create([
            'is_master' => false,
        ]);
    }

    public function test_get_shows_confirmation_page_without_consuming_token(): void
    {
        Cache::put('magic_login_TESTTOKEN', $this->master->id, now()->addMinutes(15));

        $response = $this->get('/auth/magic?token=TESTTOKEN');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('auth/magic-confirm')
            ->has('token')
            ->where('token', 'TESTTOKEN')
        );
        $this->assertGuest();
        $this->assertEquals($this->master->id, Cache::get('magic_login_TESTTOKEN'));
    }

    public function test_get_with_invalid_token_redirects_with_error(): void
    {
        $response = $this->get('/auth/magic?token=NOPE');

        $response->assertRedirect('/');
        $response->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_get_without_token_redirects_with_error(): void
    {
        $response = $this->get('/auth/magic');

        $response->assertRedirect('/');
        $response->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_post_consumes_token_and_logs_in(): void
    {
        Cache::put('magic_login_TESTTOKEN', $this->master->id, now()->addMinutes(15));

        $response = $this->post('/auth/magic', ['token' => 'TESTTOKEN']);

        $response->assertRedirect('/admin/calendar');
        $this->assertAuthenticatedAs($this->master);
        $this->assertNull(Cache::get('magic_login_TESTTOKEN'));
    }

    public function test_post_with_used_token_fails(): void
    {
        $response = $this->post('/auth/magic', ['token' => 'NOPE']);

        $response->assertRedirect('/');
        $response->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_master_redirects_to_admin_calendar(): void
    {
        Cache::put('magic_login_MASTER_TOKEN', $this->master->id, now()->addMinutes(15));

        $response = $this->post('/auth/magic', ['token' => 'MASTER_TOKEN']);

        $response->assertRedirect('/admin/calendar');
        $this->assertAuthenticatedAs($this->master);
    }

    public function test_client_redirects_to_client_bookings(): void
    {
        Cache::put('magic_login_CLIENT_TOKEN', $this->client->id, now()->addMinutes(15));

        $response = $this->post('/auth/magic', ['token' => 'CLIENT_TOKEN']);

        $response->assertRedirect('/client/bookings');
        $this->assertAuthenticatedAs($this->client);
    }

    public function test_crawler_get_then_user_post_succeeds(): void
    {
        Cache::put('magic_login_CRAWL_TOKEN', $this->master->id, now()->addMinutes(15));

        // Краулер делает GET (эмуляция)
        $getResponse = $this->get('/auth/magic?token=CRAWL_TOKEN');
        $getResponse->assertOk();
        $this->assertEquals($this->master->id, Cache::get('magic_login_CRAWL_TOKEN'));

        // Пользователь делает POST
        $postResponse = $this->post('/auth/magic', ['token' => 'CRAWL_TOKEN']);
        $postResponse->assertRedirect('/admin/calendar');
        $this->assertAuthenticatedAs($this->master);
        $this->assertNull(Cache::get('magic_login_CRAWL_TOKEN'));
    }

    public function test_throttle_blocks_excessive_requests(): void
    {
        $this->withMiddleware(ThrottleRequests::class);
        Cache::flush();

        $lastStatus = null;
        for ($i = 0; $i < 11; $i++) {
            $lastStatus = $this->get('/auth/magic?token=x')->status();
        }

        $this->assertEquals(429, $lastStatus);
    }
}
