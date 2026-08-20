<?php

namespace Tests\Feature\Channels;

use App\Models\TrackingLink;
use App\Models\User;
use App\Services\Booking\AttributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Tests\TestCase;

class AttributionServiceTest extends TestCase
{
    use RefreshDatabase;

    private AttributionService $service;

    private const COOKIE = 'booking_attribution';

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AttributionService::class);
        config()->set('booking.attribution_window_days', 7);
    }

    private function master(): User
    {
        return User::factory()->master()->create();
    }

    /** @param array<string,mixed> $cookies */
    private function request(string $uri, array $cookies = []): Request
    {
        return Request::create($uri, 'GET', [], $cookies);
    }

    /** @return array<string,mixed>|null */
    private function queuedMap(): ?array
    {
        $cookie = collect(Cookie::getQueuedCookies())
            ->first(fn ($c) => $c->getName() === self::COOKIE);

        return $cookie ? json_decode($cookie->getValue(), true) : null;
    }

    // ─── 1. valid tracked click sets attribution ───

    public function test_valid_tracked_click_sets_attribution(): void
    {
        $master = $this->master();
        $link = TrackingLink::factory()->forMaster($master)->create(['token' => 'insta1', 'is_active' => true]);

        $this->service->captureFromRequest($master, $this->request('/book/x?ref=insta1'));

        $map = $this->queuedMap();
        $this->assertSame($link->id, $map[$master->id]['link_id']);
    }

    // ─── 7/9/10. invalid / foreign / disabled ignored ───

    public function test_invalid_token_sets_no_attribution(): void
    {
        $master = $this->master();
        $this->service->captureFromRequest($master, $this->request('/book/x?ref=nonexistent'));
        $this->assertNull($this->queuedMap());
    }

    public function test_foreign_master_token_is_ignored_idor(): void
    {
        $masterA = $this->master();
        $masterB = $this->master();
        TrackingLink::factory()->forMaster($masterB)->create(['token' => 'btok', 'is_active' => true]);

        // Виджет мастера A, но токен мастера B.
        $this->service->captureFromRequest($masterA, $this->request('/book/a?ref=btok'));
        $this->assertNull($this->queuedMap());
    }

    public function test_disabled_token_sets_no_attribution(): void
    {
        $master = $this->master();
        TrackingLink::factory()->forMaster($master)->inactive()->create(['token' => 'off1']);

        $this->service->captureFromRequest($master, $this->request('/book/x?ref=off1'));
        $this->assertNull($this->queuedMap());
    }

    // ─── 8. invalid token does not reset existing valid attribution ───

    public function test_invalid_token_does_not_reset_existing_attribution(): void
    {
        $master = $this->master();
        $link = TrackingLink::factory()->forMaster($master)->create(['token' => 'insta1', 'is_active' => true]);

        $existing = [$master->id => ['link_id' => $link->id, 'expires_at' => now()->addDays(5)->getTimestamp()]];
        $req = $this->request('/book/x?ref=bad', [self::COOKIE => json_encode($existing)]);

        $this->service->captureFromRequest($master, $req);
        // Ничего не заквичено → существующая attribution не тронута.
        $this->assertNull($this->queuedMap());
        // resolve по-прежнему видит старую валидную ссылку.
        $this->assertSame($link->id, $this->service->resolveLinkId($master, $req));
    }

    // ─── 4. new tracked click replaces source ───

    public function test_new_tracked_click_replaces_source(): void
    {
        $master = $this->master();
        $insta = TrackingLink::factory()->forMaster($master)->create(['token' => 'insta1', 'is_active' => true]);
        $vk = TrackingLink::factory()->forMaster($master)->create(['token' => 'vk1', 'is_active' => true]);

        $existing = [$master->id => ['link_id' => $insta->id, 'expires_at' => now()->addDays(3)->getTimestamp()]];
        $req = $this->request('/book/x?ref=vk1', [self::COOKIE => json_encode($existing)]);

        $this->service->captureFromRequest($master, $req);
        $this->assertSame($vk->id, $this->queuedMap()[$master->id]['link_id']);
    }

    // ─── 5. new tracked click restarts 7-day TTL ───

    public function test_new_tracked_click_restarts_ttl(): void
    {
        $master = $this->master();
        $link = TrackingLink::factory()->forMaster($master)->create(['token' => 'insta1', 'is_active' => true]);

        $stale = [$master->id => ['link_id' => $link->id, 'expires_at' => now()->addDay()->getTimestamp()]];
        $req = $this->request('/book/x?ref=insta1', [self::COOKIE => json_encode($stale)]);

        $this->service->captureFromRequest($master, $req);
        $newExpiry = $this->queuedMap()[$master->id]['expires_at'];
        // Новый TTL ~7 дней (заметно больше исходного 1 дня).
        $this->assertGreaterThan(now()->addDays(6)->getTimestamp(), $newExpiry);
    }

    // ─── 6. expired window → resolve returns null (Direct) ───

    public function test_expired_window_resolves_to_null(): void
    {
        $master = $this->master();
        $link = TrackingLink::factory()->forMaster($master)->create(['token' => 'insta1', 'is_active' => true]);

        $expired = [$master->id => ['link_id' => $link->id, 'expires_at' => now()->subDay()->getTimestamp()]];
        $req = $this->request('/book/x', [self::COOKIE => json_encode($expired)]);

        $this->assertNull($this->service->resolveLinkId($master, $req));
    }

    // ─── 2/3. direct navigation neither resets nor extends ───

    public function test_direct_navigation_keeps_attribution_and_does_not_extend(): void
    {
        $master = $this->master();
        $link = TrackingLink::factory()->forMaster($master)->create(['token' => 'insta1', 'is_active' => true]);

        $expiry = now()->addDays(5)->getTimestamp();
        $existing = [$master->id => ['link_id' => $link->id, 'expires_at' => $expiry]];
        $req = $this->request('/book/x', [self::COOKIE => json_encode($existing)]); // no ref

        $this->service->captureFromRequest($master, $req);
        $this->assertNull($this->queuedMap()); // не трогаем cookie
        $this->assertSame($link->id, $this->service->resolveLinkId($master, $req)); // источник сохранён
    }

    // ─── 12. link disabled between click and booking → not fixed ───

    public function test_link_disabled_between_click_and_booking_is_not_resolved(): void
    {
        $master = $this->master();
        $link = TrackingLink::factory()->forMaster($master)->create(['token' => 'insta1', 'is_active' => true]);

        $map = [$master->id => ['link_id' => $link->id, 'expires_at' => now()->addDays(3)->getTimestamp()]];
        $req = $this->request('/book/x', [self::COOKIE => json_encode($map)]);

        // Ссылку отключили после клика.
        $link->update(['is_active' => false]);

        $this->assertNull($this->service->resolveLinkId($master, $req));
    }

    // ─── resolve ignores foreign-master stored link ───

    public function test_resolve_ignores_link_of_other_master(): void
    {
        $masterA = $this->master();
        $masterB = $this->master();
        $linkB = TrackingLink::factory()->forMaster($masterB)->create(['token' => 'btok', 'is_active' => true]);

        $map = [$masterA->id => ['link_id' => $linkB->id, 'expires_at' => now()->addDays(3)->getTimestamp()]];
        $req = $this->request('/book/a', [self::COOKIE => json_encode($map)]);

        $this->assertNull($this->service->resolveLinkId($masterA, $req));
    }
}
