<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServiceCatalogControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createWorkspaceWithRole(UserRole|string $role): array
    {
        $owner = User::factory()->master()->create();
        $workspace = Workspace::create([
            'name' => 'Test Studio ' . Str::random(6),
            'owner_id' => $owner->id,
        ]);
        $owner->update(['workspace_id' => $workspace->id, 'role' => UserRole::Owner]);

        $user = User::factory()->master()->create([
            'workspace_id' => $workspace->id,
            'role' => $role,
        ]);

        return [$workspace, $user];
    }

    private function createCatalog(Workspace $workspace, string $title = 'Стрижка'): ServiceCatalog
    {
        return ServiceCatalog::create([
            'workspace_id' => $workspace->id,
            'title' => $title,
            'base_price' => 1500,
            'base_duration' => 30,
            'is_active' => true,
        ]);
    }

    // ── Index ────────────────────────────────────────────

    public function test_owner_can_view_catalog(): void
    {
        [$ws, $owner] = $this->createWorkspaceWithRole(UserRole::Owner);
        $this->createCatalog($ws);

        $response = $this->actingAs($owner)->get('/admin/catalog');

        $response->assertStatus(200);
    }

    public function test_admin_can_view_catalog(): void
    {
        [$ws, $admin] = $this->createWorkspaceWithRole(UserRole::Admin);
        $this->createCatalog($ws);

        $response = $this->actingAs($admin)->get('/admin/catalog');

        $response->assertStatus(200);
    }

    // ── Store ────────────────────────────────────────────

    public function test_owner_can_create_catalog_item(): void
    {
        // Solo workspace: only 1 master
        $owner = User::factory()->master()->create();
        $ws = Workspace::create([
            'name' => 'Solo WS ' . Str::random(6),
            'owner_id' => $owner->id,
        ]);
        $owner->update(['workspace_id' => $ws->id, 'role' => UserRole::Owner]);

        $response = $this->actingAs($owner)->post('/admin/catalog', [
            'title' => 'Маникюр',
            'category' => 'Ногти',
            'base_price' => 2000,
            'base_duration' => 60,
            'is_active' => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('service_catalog', [
            'workspace_id' => $ws->id,
            'title' => 'Маникюр',
            'base_price' => 2000,
        ]);
        $this->assertSame(1, MasterService::count());
    }

    public function test_admin_can_create_catalog_item(): void
    {
        // Admin (not master) + 1 master owner
        $owner = User::factory()->master()->create();
        $ws = Workspace::create([
            'name' => 'Studio ' . Str::random(6),
            'owner_id' => $owner->id,
        ]);
        $owner->update(['workspace_id' => $ws->id, 'role' => UserRole::Owner]);

        $admin = User::factory()->create([
            'workspace_id' => $ws->id,
            'role' => UserRole::Admin,
            'is_master' => false,
        ]);

        $response = $this->actingAs($admin)->post('/admin/catalog', [
            'title' => 'Педикюр',
            'base_price' => 1800,
            'base_duration' => 45,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('service_catalog', [
            'workspace_id' => $ws->id,
            'title' => 'Педикюр',
        ]);
    }

    public function test_master_cannot_create_catalog_item(): void
    {
        [$ws, $master] = $this->createWorkspaceWithRole(UserRole::Master);

        $response = $this->actingAs($master)->post('/admin/catalog', [
            'title' => 'Стрижка',
            'base_price' => 1000,
            'base_duration' => 30,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('service_catalog', 0);
    }

    public function test_duplicate_title_in_same_workspace_rejected(): void
    {
        $owner = User::factory()->master()->create();
        $ws = Workspace::create([
            'name' => 'Solo WS ' . Str::random(6),
            'owner_id' => $owner->id,
        ]);
        $owner->update(['workspace_id' => $ws->id, 'role' => UserRole::Owner]);

        $this->createCatalog($ws, 'Маникюр');

        $response = $this->actingAs($owner)->post('/admin/catalog', [
            'title' => 'Маникюр',
            'base_price' => 2000,
            'base_duration' => 60,
        ]);

        $response->assertSessionHasErrors('title');
    }

    public function test_same_title_in_different_workspaces_allowed(): void
    {
        $ownerA = User::factory()->master()->create();
        $wsA = Workspace::create([
            'name' => 'WS-A ' . Str::random(6),
            'owner_id' => $ownerA->id,
        ]);
        $ownerA->update(['workspace_id' => $wsA->id, 'role' => UserRole::Owner]);

        $ownerB = User::factory()->master()->create();
        $wsB = Workspace::create([
            'name' => 'WS-B ' . Str::random(6),
            'owner_id' => $ownerB->id,
        ]);
        $ownerB->update(['workspace_id' => $wsB->id, 'role' => UserRole::Owner]);

        $this->createCatalog($wsA, 'Стрижка');

        $response = $this->actingAs($ownerB)->post('/admin/catalog', [
            'title' => 'Стрижка',
            'base_price' => 1200,
            'base_duration' => 30,
        ]);

        $response->assertRedirect();
        $this->assertSame(2, ServiceCatalog::count());
    }

    // ── Store validation ─────────────────────────────────

    public function test_store_requires_title(): void
    {
        $owner = User::factory()->master()->create();
        $ws = Workspace::create([
            'name' => 'Solo WS ' . Str::random(6),
            'owner_id' => $owner->id,
        ]);
        $owner->update(['workspace_id' => $ws->id, 'role' => UserRole::Owner]);

        $response = $this->actingAs($owner)->post('/admin/catalog', [
            'base_price' => 1500,
            'base_duration' => 30,
        ]);

        $response->assertSessionHasErrors('title');
        $this->assertDatabaseCount('service_catalog', 0);
    }

    public function test_store_requires_price_and_duration(): void
    {
        $owner = User::factory()->master()->create();
        $ws = Workspace::create([
            'name' => 'Solo WS ' . Str::random(6),
            'owner_id' => $owner->id,
        ]);
        $owner->update(['workspace_id' => $ws->id, 'role' => UserRole::Owner]);

        $response = $this->actingAs($owner)->post('/admin/catalog', [
            'title' => 'Маникюр',
            'base_price' => -10,
            'base_duration' => 0,
        ]);

        $response->assertSessionHasErrors(['base_price', 'base_duration']);
        $this->assertDatabaseCount('service_catalog', 0);
    }

    public function test_store_creates_inactive_service(): void
    {
        $owner = User::factory()->master()->create();
        $ws = Workspace::create([
            'name' => 'Solo WS ' . Str::random(6),
            'owner_id' => $owner->id,
        ]);
        $owner->update(['workspace_id' => $ws->id, 'role' => UserRole::Owner]);

        $response = $this->actingAs($owner)->post('/admin/catalog', [
            'title' => 'Скрытая услуга',
            'base_price' => 900,
            'base_duration' => 20,
            'is_active' => false,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('service_catalog', [
            'workspace_id' => $ws->id,
            'title' => 'Скрытая услуга',
            'is_active' => false,
        ]);
    }

    // ── Update ───────────────────────────────────────────

    public function test_owner_can_update_catalog_item(): void
    {
        [$ws, $owner] = $this->createWorkspaceWithRole(UserRole::Owner);
        $catalog = $this->createCatalog($ws);

        $response = $this->actingAs($owner)->put("/admin/catalog/{$catalog->id}", [
            'title' => 'Стрижка',
            'base_price' => 2000,
            'base_duration' => 45,
            'is_active' => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('service_catalog', [
            'id' => $catalog->id,
            'base_price' => 2000,
            'base_duration' => 45,
        ]);
    }

    public function test_update_changes_base_price(): void
    {
        [$ws, $owner] = $this->createWorkspaceWithRole(UserRole::Owner);
        $catalog = $this->createCatalog($ws);

        $this->actingAs($owner)->put("/admin/catalog/{$catalog->id}", [
            'title' => 'Стрижка',
            'base_price' => 3000,
            'base_duration' => 30,
            'is_active' => true,
        ]);

        $catalog->refresh();
        $this->assertSame('3000.00', $catalog->base_price);
    }

    public function test_update_without_category_preserves_existing_category(): void
    {
        [$ws, $owner] = $this->createWorkspaceWithRole(UserRole::Owner);
        $catalog = ServiceCatalog::create([
            'workspace_id' => $ws->id,
            'title' => 'Маникюр',
            'category' => 'Ногтевой сервис',
            'base_price' => 1500,
            'base_duration' => 30,
            'is_active' => true,
        ]);

        // UI больше не отправляет category — payload без этого поля.
        $response = $this->actingAs($owner)->put("/admin/catalog/{$catalog->id}", [
            'title' => 'Маникюр',
            'base_price' => 1800,
            'base_duration' => 40,
            'is_active' => true,
        ]);

        $response->assertRedirect();
        $catalog->refresh();
        $this->assertSame('Ногтевой сервис', $catalog->category);
        $this->assertSame('1800.00', $catalog->base_price);
        $this->assertSame(40, $catalog->base_duration);
    }

    public function test_update_reactivates_inactive_service(): void
    {
        [$ws, $owner] = $this->createWorkspaceWithRole(UserRole::Owner);
        $catalog = ServiceCatalog::create([
            'workspace_id' => $ws->id,
            'title' => 'Педикюр',
            'base_price' => 1500,
            'base_duration' => 30,
            'is_active' => false,
        ]);

        $response = $this->actingAs($owner)->put("/admin/catalog/{$catalog->id}", [
            'title' => 'Педикюр',
            'base_price' => 1500,
            'base_duration' => 30,
            'is_active' => true,
        ]);

        $response->assertRedirect();
        $catalog->refresh();
        $this->assertTrue($catalog->is_active);
    }

    public function test_update_duplicate_title_rejected(): void
    {
        [$ws, $owner] = $this->createWorkspaceWithRole(UserRole::Owner);
        $this->createCatalog($ws, 'Маникюр');
        $pedicure = $this->createCatalog($ws, 'Педикюр');

        $response = $this->actingAs($owner)->put("/admin/catalog/{$pedicure->id}", [
            'title' => 'Маникюр',
            'base_price' => 1800,
            'base_duration' => 45,
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors('title');
    }

    public function test_update_same_title_no_conflict(): void
    {
        [$ws, $owner] = $this->createWorkspaceWithRole(UserRole::Owner);
        $catalog = $this->createCatalog($ws, 'Маникюр');

        $response = $this->actingAs($owner)->put("/admin/catalog/{$catalog->id}", [
            'title' => 'Маникюр',
            'base_price' => 2500,
            'base_duration' => 60,
            'is_active' => false,
        ]);

        $response->assertRedirect();
        $catalog->refresh();
        $this->assertSame('2500.00', $catalog->base_price);
        $this->assertFalse($catalog->is_active);
    }

    public function test_foreign_workspace_cannot_update(): void
    {
        [$wsA, $ownerA] = $this->createWorkspaceWithRole(UserRole::Owner);
        [$wsB, $ownerB] = $this->createWorkspaceWithRole(UserRole::Owner);
        $catalog = $this->createCatalog($wsA);

        $response = $this->actingAs($ownerB)->put("/admin/catalog/{$catalog->id}", [
            'title' => 'Стрижка',
            'base_price' => 100,
            'base_duration' => 10,
            'is_active' => true,
        ]);

        $response->assertForbidden();
    }

    // ── Destroy ──────────────────────────────────────────

    public function test_destroy_with_ms_deletes_both(): void
    {
        [$ws, $owner] = $this->createWorkspaceWithRole(UserRole::Owner);
        $catalog = $this->createCatalog($ws);
        $master = User::factory()->master()->create(['workspace_id' => $ws->id]);

        MasterService::create([
            'master_id' => $master->id,
            'catalog_id' => $catalog->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($owner)->delete("/admin/catalog/{$catalog->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('service_catalog', ['id' => $catalog->id]);
        $this->assertDatabaseMissing('master_service', ['catalog_id' => $catalog->id]);
    }

    public function test_destroy_passes_if_no_master_services(): void
    {
        [$ws, $owner] = $this->createWorkspaceWithRole(UserRole::Owner);
        $catalog = $this->createCatalog($ws);

        $response = $this->actingAs($owner)->delete("/admin/catalog/{$catalog->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('service_catalog', ['id' => $catalog->id]);
    }

    public function test_foreign_workspace_cannot_delete(): void
    {
        [$wsA, $ownerA] = $this->createWorkspaceWithRole(UserRole::Owner);
        [$wsB, $ownerB] = $this->createWorkspaceWithRole(UserRole::Owner);
        $catalog = $this->createCatalog($wsA);

        $response = $this->actingAs($ownerB)->delete("/admin/catalog/{$catalog->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('service_catalog', ['id' => $catalog->id]);
    }
}
