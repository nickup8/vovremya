<?php

namespace Tests\Feature\Channels;

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\TrackingLink;
use App\Models\User;
use App\Services\Analytics\SourceAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SourceAnalyticsTest extends TestCase
{
    use MakesTariffMasters, RefreshDatabase;

    private SourceAnalyticsService $service;

    private User $master;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SourceAnalyticsService::class);
        $this->master = $this->proMaster();
    }

    private Carbon $periodStart;

    private Carbon $periodEnd;

    private function period(string $from, string $to): void
    {
        $this->periodStart = Carbon::parse($from, 'UTC')->startOfDay();
        $this->periodEnd = Carbon::parse($to, 'UTC')->endOfDay();
    }

    /** @return array<int,array<string,mixed>> */
    private function build(): array
    {
        return $this->service->buildForMaster($this->master, $this->periodStart, $this->periodEnd);
    }

    private function bySourceKey(array $sources, string $key): ?array
    {
        foreach ($sources as $s) {
            if ($s['key'] === $key) {
                return $s;
            }
        }

        return null;
    }

    private int $slot = 0;

    private function makeAppt(array $attrs): Appointment
    {
        // Уникальный start_time на запись — иначе ловим appointments_no_overlap.
        $this->slot++;
        $start = Carbon::parse('2026-07-01 08:00:00', 'UTC')->addHours($this->slot * 2);

        // created_at не входит в $fillable → Eloquent проставит now(); задаём явно после создания.
        $createdAt = $attrs['created_at'] ?? null;
        unset($attrs['created_at']);

        $appt = Appointment::factory()->forMaster($this->master)->create(array_merge([
            'price' => 1000,
            'duration' => 60,
            'status' => AppointmentStatus::Booked,
            'start_time' => $start,
        ], $attrs));

        if ($createdAt !== null) {
            $appt->forceFill(['created_at' => $createdAt])->saveQuietly();
        }

        return $appt->fresh();
    }

    // ─── System sources: Direct / Manual ───

    public function test_widget_booking_without_attribution_is_direct(): void
    {
        $this->period('2026-09-01', '2026-09-30');
        $this->makeAppt(['created_at' => Carbon::parse('2026-09-10', 'UTC'), 'source' => null, 'tracking_link_id' => null]);

        $direct = $this->bySourceKey($this->build(), SourceAnalyticsService::KEY_DIRECT);
        $this->assertNotNull($direct);
        $this->assertSame(1, $direct['created_count']);
        $this->assertSame(SourceAnalyticsService::NAME_DIRECT, $direct['name']);
    }

    public function test_manual_booking_is_recorded_by_master_and_not_direct(): void
    {
        $this->period('2026-09-01', '2026-09-30');
        $this->makeAppt(['created_at' => Carbon::parse('2026-09-10', 'UTC'), 'source' => AppointmentSource::Admin, 'tracking_link_id' => null]);

        $sources = $this->build();
        $manual = $this->bySourceKey($sources, SourceAnalyticsService::KEY_MANUAL);
        $this->assertNotNull($manual);
        $this->assertSame('Записано мастером', $manual['name']);
        // не попал в Direct
        $this->assertNull($this->bySourceKey($sources, SourceAnalyticsService::KEY_DIRECT));
    }

    public function test_telegram_source_without_tracking_is_direct_not_broken(): void
    {
        $this->period('2026-09-01', '2026-09-30');
        // Telegram — технический source, без tracking_link → Direct-категория marketing-аналитики.
        $this->makeAppt(['created_at' => Carbon::parse('2026-09-10', 'UTC'), 'source' => AppointmentSource::Telegram, 'tracking_link_id' => null]);

        $direct = $this->bySourceKey($this->build(), SourceAnalyticsService::KEY_DIRECT);
        $this->assertSame(1, $direct['created_count']);
    }

    // ─── Tracking link + same names not merged ───

    public function test_same_named_links_are_not_merged(): void
    {
        $this->period('2026-09-01', '2026-09-30');
        $l1 = TrackingLink::factory()->forMaster($this->master)->create(['name' => 'Блогер']);
        $l2 = TrackingLink::factory()->forMaster($this->master)->create(['name' => 'Блогер']);

        $this->makeAppt(['created_at' => Carbon::parse('2026-09-05', 'UTC'), 'tracking_link_id' => $l1->id]);
        $this->makeAppt(['created_at' => Carbon::parse('2026-09-06', 'UTC'), 'tracking_link_id' => $l2->id]);

        $sources = $this->build();
        $this->assertNotNull($this->bySourceKey($sources, 'link:'.$l1->id));
        $this->assertNotNull($this->bySourceKey($sources, 'link:'.$l2->id));
    }

    public function test_inactive_historical_link_still_shows_in_analytics(): void
    {
        $this->period('2026-09-01', '2026-09-30');
        $link = TrackingLink::factory()->forMaster($this->master)->inactive()->create(['name' => 'VK старое']);
        $this->makeAppt(['created_at' => Carbon::parse('2026-09-05', 'UTC'), 'tracking_link_id' => $link->id]);

        $this->assertNotNull($this->bySourceKey($this->build(), 'link:'.$link->id));
    }

    // ─── Period semantics: created / cancelled / completed разнесены ───

    public function test_created_counts_by_created_at(): void
    {
        $this->period('2026-08-01', '2026-08-31');
        $link = TrackingLink::factory()->forMaster($this->master)->create(['name' => 'Instagram']);

        // Создан в августе, завершён в сентябре.
        $this->makeAppt([
            'created_at' => Carbon::parse('2026-08-28', 'UTC'),
            'completed_at' => Carbon::parse('2026-09-10', 'UTC'),
            'status' => AppointmentStatus::Paid,
            'tracking_link_id' => $link->id,
        ]);

        $aug = $this->bySourceKey($this->build(), 'link:'.$link->id);
        $this->assertSame(1, $aug['created_count']);
        $this->assertSame(0, $aug['completed_count']); // завершение в сентябре
        $this->assertSame(0.0, $aug['revenue']);
    }

    public function test_completed_and_revenue_count_by_completed_at(): void
    {
        $this->period('2026-09-01', '2026-09-30');
        $link = TrackingLink::factory()->forMaster($this->master)->create(['name' => 'Instagram']);

        $this->makeAppt([
            'created_at' => Carbon::parse('2026-08-28', 'UTC'),
            'completed_at' => Carbon::parse('2026-09-10', 'UTC'),
            'status' => AppointmentStatus::Paid,
            'price' => 2500,
            'tracking_link_id' => $link->id,
        ]);

        $sep = $this->bySourceKey($this->build(), 'link:'.$link->id);
        $this->assertSame(0, $sep['created_count']); // создан в августе
        $this->assertSame(1, $sep['completed_count']);
        $this->assertSame(2500.0, $sep['revenue']);
        $this->assertSame(2500.0, $sep['average_check']);
    }

    public function test_cancelled_counts_by_cancelled_at(): void
    {
        $this->period('2026-09-01', '2026-09-30');
        $link = TrackingLink::factory()->forMaster($this->master)->create(['name' => 'Instagram']);

        $this->makeAppt([
            'created_at' => Carbon::parse('2026-08-15', 'UTC'),
            'cancelled_at' => Carbon::parse('2026-09-05', 'UTC'),
            'status' => AppointmentStatus::Cancelled,
            'tracking_link_id' => $link->id,
        ]);

        $sep = $this->bySourceKey($this->build(), 'link:'.$link->id);
        $this->assertSame(1, $sep['cancelled_count']);
        $this->assertSame(0, $sep['created_count']); // создан в августе
    }

    // ─── NEW / RETURNING ───

    public function test_first_completed_visit_is_new_second_is_returning(): void
    {
        $this->period('2026-09-01', '2026-09-30');
        $client = Client::factory()->create(['user_id' => $this->master->id]);
        $insta = TrackingLink::factory()->forMaster($this->master)->create(['name' => 'Instagram']);
        $vk = TrackingLink::factory()->forMaster($this->master)->create(['name' => 'VK']);

        // Первый завершённый визит — Instagram (10 сент).
        $this->makeAppt([
            'client_id' => $client->id, 'tracking_link_id' => $insta->id,
            'status' => AppointmentStatus::Paid,
            'completed_at' => Carbon::parse('2026-09-10', 'UTC'),
            'created_at' => Carbon::parse('2026-09-01', 'UTC'),
        ]);
        // Второй — VK (25 сент).
        $this->makeAppt([
            'client_id' => $client->id, 'tracking_link_id' => $vk->id,
            'status' => AppointmentStatus::Paid,
            'completed_at' => Carbon::parse('2026-09-25', 'UTC'),
            'created_at' => Carbon::parse('2026-09-03', 'UTC'),
        ]);

        $sources = $this->build();
        $instaSrc = $this->bySourceKey($sources, 'link:'.$insta->id);
        $vkSrc = $this->bySourceKey($sources, 'link:'.$vk->id);

        $this->assertSame(1, $instaSrc['new_clients_count']);
        $this->assertSame(0, $instaSrc['returning_clients_count']);
        $this->assertSame(0, $vkSrc['new_clients_count']);
        $this->assertSame(1, $vkSrc['returning_clients_count']);
    }

    public function test_other_masters_history_does_not_affect_new(): void
    {
        $this->period('2026-09-01', '2026-09-30');
        $otherMaster = $this->proMaster();
        // Один и тот же телефон — но Client привязан к мастеру, поэтому «тот же клиент у другого мастера»
        // моделируется отдельным Client у другого мастера. Здесь проверяем, что чужие завершения не влияют.
        $client = Client::factory()->create(['user_id' => $this->master->id]);
        $foreignClient = Client::factory()->create(['user_id' => $otherMaster->id]);

        // У другого мастера ранее был завершённый визит этого «человека».
        Appointment::factory()->forMaster($otherMaster)->create([
            'client_id' => $foreignClient->id, 'status' => AppointmentStatus::Paid,
            'completed_at' => Carbon::parse('2026-08-01', 'UTC'),
        ]);

        // У текущего мастера — первый завершённый визит.
        $this->makeAppt([
            'client_id' => $client->id, 'status' => AppointmentStatus::Paid,
            'completed_at' => Carbon::parse('2026-09-10', 'UTC'),
            'source' => null, 'tracking_link_id' => null,
        ]);

        $direct = $this->bySourceKey($this->build(), SourceAnalyticsService::KEY_DIRECT);
        $this->assertSame(1, $direct['new_clients_count']);
    }

    public function test_distinct_clients_not_appointment_count(): void
    {
        $this->period('2026-09-01', '2026-09-30');
        $client = Client::factory()->create(['user_id' => $this->master->id]);
        $link = TrackingLink::factory()->forMaster($this->master)->create(['name' => 'Instagram']);

        // Один новый клиент, 3 завершённые услуги в периоде.
        // Первая — NEW, последующие — RETURNING (есть более ранний completed).
        foreach (['2026-09-05', '2026-09-15', '2026-09-25'] as $d) {
            $this->makeAppt([
                'client_id' => $client->id, 'tracking_link_id' => $link->id,
                'status' => AppointmentStatus::Paid,
                'completed_at' => Carbon::parse($d, 'UTC'),
            ]);
        }

        $src = $this->bySourceKey($this->build(), 'link:'.$link->id);
        $this->assertSame(3, $src['completed_count']);
        $this->assertSame(1, $src['new_clients_count']); // не 3
        $this->assertSame(1, $src['returning_clients_count']); // distinct
    }

    public function test_null_client_id_does_not_break_aggregation(): void
    {
        $this->period('2026-09-01', '2026-09-30');
        $this->makeAppt([
            'client_id' => null, 'status' => AppointmentStatus::Paid,
            'completed_at' => Carbon::parse('2026-09-10', 'UTC'),
            'source' => null, 'tracking_link_id' => null, 'price' => 1500,
        ]);

        $direct = $this->bySourceKey($this->build(), SourceAnalyticsService::KEY_DIRECT);
        $this->assertSame(1, $direct['completed_count']);
        $this->assertSame(1500.0, $direct['revenue']);
        $this->assertSame(0, $direct['new_clients_count']);
        $this->assertSame(0, $direct['returning_clients_count']);
    }

    // ─── avg_check safe on zero completed ───

    public function test_average_check_zero_when_no_completed(): void
    {
        $this->period('2026-09-01', '2026-09-30');
        $link = TrackingLink::factory()->forMaster($this->master)->create(['name' => 'Instagram']);
        $this->makeAppt(['created_at' => Carbon::parse('2026-09-05', 'UTC'), 'tracking_link_id' => $link->id, 'status' => AppointmentStatus::Booked]);

        $src = $this->bySourceKey($this->build(), 'link:'.$link->id);
        $this->assertSame(0.0, $src['average_check']);
    }

    // ─── TOP-5 ───

    public function test_top5_limits_to_five_sorted_by_revenue_desc(): void
    {
        $this->period('2026-09-01', '2026-09-30');
        $revenues = [500, 4000, 1000, 3000, 2000, 100, 50];
        foreach ($revenues as $i => $rev) {
            $link = TrackingLink::factory()->forMaster($this->master)->create(['name' => "L{$i}"]);
            $this->makeAppt([
                'tracking_link_id' => $link->id, 'status' => AppointmentStatus::Paid,
                'completed_at' => Carbon::parse('2026-09-10', 'UTC'), 'price' => $rev,
            ]);
        }

        $top = $this->service->topByRevenue($this->build(), 5);
        $this->assertCount(5, $top);
        $this->assertSame(4000.0, $top[0]['revenue']);
        $this->assertSame(3000.0, $top[1]['revenue']);
        $this->assertSame(2000.0, $top[2]['revenue']);
    }

    // ─── Timezone: month boundary in master tz ───

    public function test_completed_at_near_utc_midnight_falls_into_correct_master_month(): void
    {
        // Мастер в Europe/Moscow (UTC+3). completed_at = 2026-08-31 22:30 UTC = 01:30 1 сент по МСК.
        // Для сентябрьского периода (в МСК) визит должен попасть в сентябрь.
        $link = TrackingLink::factory()->forMaster($this->master)->create(['name' => 'Instagram']);
        $this->makeAppt([
            'tracking_link_id' => $link->id, 'status' => AppointmentStatus::Paid,
            'completed_at' => Carbon::parse('2026-08-31 22:30:00', 'UTC'), 'price' => 1000,
        ]);

        // Сентябрьские границы в МСК → UTC.
        $start = Carbon::parse('2026-09-01', 'Europe/Moscow')->startOfDay()->utc();
        $end = Carbon::parse('2026-09-30', 'Europe/Moscow')->endOfDay()->utc();
        $sources = $this->service->buildForMaster($this->master, $start, $end);

        $src = $this->bySourceKey($sources, 'link:'.$link->id);
        $this->assertNotNull($src);
        $this->assertSame(1, $src['completed_count']);
    }
}
