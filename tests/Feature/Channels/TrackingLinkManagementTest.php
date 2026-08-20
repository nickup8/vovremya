<?php

namespace Tests\Feature\Channels;

use App\Models\TrackingLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackingLinkManagementTest extends TestCase
{
    use MakesTariffMasters, RefreshDatabase;

    // ─── ПРОФИ CRUD ───

    public function test_pro_master_can_create_link_with_backend_token(): void
    {
        $master = $this->proMaster();

        $this->actingAs($master)
            ->post(route('admin.tracking-links.store'), ['name' => 'Instagram'])
            ->assertRedirect();

        $link = TrackingLink::where('master_id', $master->id)->first();
        $this->assertNotNull($link);
        $this->assertSame('Instagram', $link->name);
        $this->assertNotEmpty($link->token);
        $this->assertTrue($link->is_active);
    }

    public function test_token_is_generated_by_backend_and_ignores_client_supplied_token(): void
    {
        $master = $this->proMaster();

        $this->actingAs($master)
            ->post(route('admin.tracking-links.store'), ['name' => 'VK', 'token' => 'hacktoken'])
            ->assertRedirect();

        $link = TrackingLink::where('master_id', $master->id)->first();
        $this->assertNotSame('hacktoken', $link->token);
    }

    public function test_tokens_are_unique(): void
    {
        $master = $this->proMaster();

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($master)->post(route('admin.tracking-links.store'), ['name' => "L{$i}"]);
        }

        $tokens = TrackingLink::where('master_id', $master->id)->pluck('token');
        $this->assertSame($tokens->count(), $tokens->unique()->count());
    }

    public function test_link_belongs_to_creating_master(): void
    {
        $master = $this->proMaster();
        $this->actingAs($master)->post(route('admin.tracking-links.store'), ['name' => '2GIS']);

        $this->assertSame($master->id, TrackingLink::first()->master_id);
    }

    public function test_pro_master_can_rename(): void
    {
        $master = $this->proMaster();
        $link = TrackingLink::factory()->forMaster($master)->create(['name' => 'Old']);

        $this->actingAs($master)
            ->put(route('admin.tracking-links.update', $link), ['name' => 'New'])
            ->assertRedirect();

        $this->assertSame('New', $link->fresh()->name);
    }

    public function test_pro_master_can_deactivate_and_activate(): void
    {
        $master = $this->proMaster();
        $link = TrackingLink::factory()->forMaster($master)->create(['is_active' => true]);

        $this->actingAs($master)
            ->patch(route('admin.tracking-links.active', $link), ['is_active' => false])
            ->assertRedirect();
        $this->assertFalse($link->fresh()->is_active);

        $this->actingAs($master)
            ->patch(route('admin.tracking-links.active', $link), ['is_active' => true])
            ->assertRedirect();
        $this->assertTrue($link->fresh()->is_active);
    }

    public function test_no_delete_route_exists(): void
    {
        $this->assertFalse(
            app('router')->getRoutes()->hasNamedRoute('admin.tracking-links.destroy')
        );
    }

    public function test_no_artificial_limit_on_number_of_links(): void
    {
        $master = $this->proMaster();

        for ($i = 0; $i < 25; $i++) {
            $this->actingAs($master)
                ->post(route('admin.tracking-links.store'), ['name' => "L{$i}"])
                ->assertRedirect();
        }

        $this->assertSame(25, TrackingLink::where('master_id', $master->id)->count());
    }

    public function test_master_cannot_modify_another_masters_link(): void
    {
        $masterA = $this->proMaster();
        $masterB = $this->proMaster();
        $linkB = TrackingLink::factory()->forMaster($masterB)->create(['name' => 'B-link']);

        $this->actingAs($masterA)
            ->put(route('admin.tracking-links.update', $linkB), ['name' => 'hijack'])
            ->assertForbidden();

        $this->assertSame('B-link', $linkB->fresh()->name);
    }

    // ─── START gating (backend 403) ───

    public function test_start_master_cannot_create_link(): void
    {
        $master = $this->startMaster();

        $this->actingAs($master)
            ->post(route('admin.tracking-links.store'), ['name' => 'Instagram'])
            ->assertForbidden();

        $this->assertSame(0, TrackingLink::count());
    }

    public function test_start_master_cannot_rename(): void
    {
        $master = $this->startMaster();
        $link = TrackingLink::factory()->forMaster($master)->create(['name' => 'Old']);

        $this->actingAs($master)
            ->put(route('admin.tracking-links.update', $link), ['name' => 'New'])
            ->assertForbidden();
    }

    public function test_start_master_cannot_toggle_active(): void
    {
        $master = $this->startMaster();
        $link = TrackingLink::factory()->forMaster($master)->create(['is_active' => true]);

        $this->actingAs($master)
            ->patch(route('admin.tracking-links.active', $link), ['is_active' => false])
            ->assertForbidden();
    }
}
