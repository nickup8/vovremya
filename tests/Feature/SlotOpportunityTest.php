<?php

namespace Tests\Feature;

use App\Enums\SlotOpportunitySourceType;
use App\Enums\SlotOpportunityStatus;
use App\Models\Appointment;
use App\Models\MasterService;
use App\Models\ServiceCatalog;
use App\Models\SlotOpportunity;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SlotOpportunityService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class SlotOpportunityTest extends TestCase
{
    use RefreshDatabase;

    private SlotOpportunityService $service;
    private User $master;
    private Workspace $ws;
    private MasterService $masterService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SlotOpportunityService;

        $this->master = User::factory()->master()->create();
        $this->ws = Workspace::create(['name' => 'WS Test', 'owner_id' => $this->master->id]);
        $this->master->update(['workspace_id' => $this->ws->id]);

        $catalog = ServiceCatalog::create([
            'workspace_id' => $this->ws->id,
            'title' => 'Массаж',
            'base_price' => 2000,
            'base_duration' => 60,
        ]);

        $this->masterService = MasterService::create([
            'master_id' => $this->master->id,
            'catalog_id' => $catalog->id,
            'is_active' => true,
        ]);
    }

    private function validArgs(array $overrides = []): array
    {
        return array_merge([
            'originEventId' => (string) Str::uuid(),
            'chainId' => null,
            'workspaceId' => $this->ws->id,
            'masterId' => $this->master->id,
            'masterServiceId' => $this->masterService->id,
            'sourceAppointmentId' => null,
            'sourceType' => SlotOpportunitySourceType::Cancellation,
            'startTime' => Carbon::tomorrow()->setTime(14, 0),
            'duration' => 60,
        ], $overrides);
    }

    // ── Root opportunity creation ─────────────────────────

    public function test_creates_root_opportunity(): void
    {
        $opp = $this->service->createFromFreedWindow(...$this->validArgs());

        $this->assertNotNull($opp);
        $this->assertEquals(SlotOpportunityStatus::Open, $opp->status);
        $this->assertNotNull($opp->id);
    }

    public function test_root_opportunity_gets_new_chain_id(): void
    {
        $opp = $this->service->createFromFreedWindow(...$this->validArgs());

        $this->assertNotNull($opp->chain_id);
        $this->assertNotEmpty($opp->chain_id);
    }

    public function test_chain_id_preserved_when_provided(): void
    {
        $chainId = (string) Str::uuid();

        $opp = $this->service->createFromFreedWindow(...$this->validArgs([
            'chainId' => $chainId,
        ]));

        $this->assertEquals($chainId, $opp->chain_id);
    }

    // ── Source type casts ─────────────────────────────────

    public function test_source_type_cancellation(): void
    {
        $opp = $this->service->createFromFreedWindow(...$this->validArgs([
            'sourceType' => SlotOpportunitySourceType::Cancellation,
        ]));

        $this->assertEquals(SlotOpportunitySourceType::Cancellation, $opp->source_type);
    }

    public function test_source_type_reschedule(): void
    {
        $opp = $this->service->createFromFreedWindow(...$this->validArgs([
            'sourceType' => SlotOpportunitySourceType::Reschedule,
        ]));

        $this->assertEquals(SlotOpportunitySourceType::Reschedule, $opp->source_type);
    }

    public function test_source_type_autofill_reschedule(): void
    {
        $opp = $this->service->createFromFreedWindow(...$this->validArgs([
            'sourceType' => SlotOpportunitySourceType::AutoFillReschedule,
        ]));

        $this->assertEquals(SlotOpportunitySourceType::AutoFillReschedule, $opp->source_type);
    }

    // ── Default status ────────────────────────────────────

    public function test_default_status_is_open(): void
    {
        $opp = $this->service->createFromFreedWindow(...$this->validArgs());

        $this->assertEquals(SlotOpportunityStatus::Open, $opp->fresh()->status);
    }

    // ── Duration validation ───────────────────────────────

    public function test_duration_zero_rejected(): void
    {
        $this->expectException(\DomainException::class);

        $this->service->createFromFreedWindow(...$this->validArgs([
            'duration' => 0,
        ]));
    }

    public function test_duration_negative_rejected(): void
    {
        $this->expectException(\DomainException::class);

        $this->service->createFromFreedWindow(...$this->validArgs([
            'duration' => -10,
        ]));
    }

    // ── Past start_time ───────────────────────────────────

    public function test_past_start_time_returns_null(): void
    {
        $opp = $this->service->createFromFreedWindow(...$this->validArgs([
            'startTime' => Carbon::yesterday()->setTime(14, 0),
        ]));

        $this->assertNull($opp);
    }

    // ── IDs preserved ─────────────────────────────────────

    public function test_workspace_master_service_ids_preserved(): void
    {
        $opp = $this->service->createFromFreedWindow(...$this->validArgs());

        $this->assertEquals($this->ws->id, $opp->workspace_id);
        $this->assertEquals($this->master->id, $opp->master_id);
        $this->assertEquals($this->masterService->id, $opp->master_service_id);
    }

    // ── Master/workspace consistency ──────────────────────

    public function test_master_from_other_workspace_rejected(): void
    {
        $otherMaster = User::factory()->master()->create();

        $this->expectException(\DomainException::class);

        $this->service->createFromFreedWindow(...$this->validArgs([
            'masterId' => $otherMaster->id,
        ]));
    }

    public function test_master_service_of_other_master_rejected(): void
    {
        $otherMaster = User::factory()->master()->create();
        $otherMaster->update(['workspace_id' => $this->ws->id]);

        $otherCatalog = ServiceCatalog::create([
            'workspace_id' => $this->ws->id,
            'title' => 'Стрижка',
            'base_price' => 1000,
            'base_duration' => 30,
        ]);

        $otherMs = MasterService::create([
            'master_id' => $otherMaster->id,
            'catalog_id' => $otherCatalog->id,
            'is_active' => true,
        ]);

        $this->expectException(\DomainException::class);

        $this->service->createFromFreedWindow(...$this->validArgs([
            'masterServiceId' => $otherMs->id,
        ]));
    }

    // ── Inactive MasterService allowed ────────────────────

    public function test_inactive_master_service_does_not_block(): void
    {
        $this->masterService->update(['is_active' => false]);

        $opp = $this->service->createFromFreedWindow(...$this->validArgs());

        $this->assertNotNull($opp);
        $this->assertEquals(SlotOpportunityStatus::Open, $opp->status);
    }

    // ── Source appointment relationship ───────────────────

    public function test_source_appointment_relationship(): void
    {
        $client = \App\Models\Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
        ]);

        $appointment = Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client)
            ->withMasterService($this->masterService)
            ->create([
                'status' => 'booked',
                'start_time' => Carbon::tomorrow()->setTime(14, 0),
                'duration' => 60,
            ]);

        $opp = $this->service->createFromFreedWindow(...$this->validArgs([
            'sourceAppointmentId' => $appointment->id,
        ]));

        $this->assertEquals($appointment->id, $opp->sourceAppointment->id);
    }

    public function test_source_appointment_deletion_nulls_id(): void
    {
        $client = \App\Models\Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
        ]);

        $appointment = Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client)
            ->withMasterService($this->masterService)
            ->create([
                'status' => 'cancelled',
                'start_time' => Carbon::tomorrow()->setTime(14, 0),
                'duration' => 60,
            ]);

        $opp = $this->service->createFromFreedWindow(...$this->validArgs([
            'sourceAppointmentId' => $appointment->id,
        ]));

        $appointment->delete();

        $this->assertNull($opp->fresh()->source_appointment_id);
    }

    // ── Idempotency: same origin_event_id ─────────────────

    public function test_retry_same_origin_returns_same_opportunity(): void
    {
        $args = $this->validArgs();

        $opp1 = $this->service->createFromFreedWindow(...$args);
        $opp2 = $this->service->createFromFreedWindow(...$args);

        $this->assertEquals($opp1->id, $opp2->id);
    }

    public function test_retry_does_not_create_duplicate(): void
    {
        $args = $this->validArgs();

        $this->service->createFromFreedWindow(...$args);
        $this->service->createFromFreedWindow(...$args);

        $this->assertCount(1, SlotOpportunity::all());
    }

    public function test_retry_with_null_chain_preserves_generated_chain(): void
    {
        $originEventId = (string) Str::uuid();
        $args = $this->validArgs(['chainId' => null, 'originEventId' => $originEventId]);

        $opp1 = $this->service->createFromFreedWindow(...$args);
        $generatedChain = $opp1->chain_id;

        $opp2 = $this->service->createFromFreedWindow(...$this->validArgs([
            'chainId' => null,
            'originEventId' => $originEventId,
        ]));

        $this->assertEquals($generatedChain, $opp2->chain_id);
    }

    // ── Conflicting origin_event_id payload ───────────────

    public function test_same_origin_different_duration_rejected(): void
    {
        $originEventId = (string) Str::uuid();

        $this->service->createFromFreedWindow(...$this->validArgs([
            'originEventId' => $originEventId,
            'duration' => 60,
        ]));

        $this->expectException(\DomainException::class);

        $this->service->createFromFreedWindow(...$this->validArgs([
            'originEventId' => $originEventId,
            'duration' => 90,
        ]));
    }

    public function test_same_origin_different_start_time_rejected(): void
    {
        $originEventId = (string) Str::uuid();

        $this->service->createFromFreedWindow(...$this->validArgs([
            'originEventId' => $originEventId,
            'startTime' => Carbon::tomorrow()->setTime(14, 0),
        ]));

        $this->expectException(\DomainException::class);

        $this->service->createFromFreedWindow(...$this->validArgs([
            'originEventId' => $originEventId,
            'startTime' => Carbon::tomorrow()->setTime(15, 0),
        ]));
    }

    public function test_same_origin_different_master_rejected(): void
    {
        $originEventId = (string) Str::uuid();

        $this->service->createFromFreedWindow(...$this->validArgs([
            'originEventId' => $originEventId,
        ]));

        $otherMaster = User::factory()->master()->create();
        $otherMaster->update(['workspace_id' => $this->ws->id]);

        $this->expectException(\DomainException::class);

        $this->service->createFromFreedWindow(...$this->validArgs([
            'originEventId' => $originEventId,
            'masterId' => $otherMaster->id,
        ]));
    }

    // ── Different origin_event_id for same appointment ────

    public function test_different_origin_for_same_appointment_allowed(): void
    {
        $client = \App\Models\Client::factory()->create([
            'user_id' => $this->master->id,
            'workspace_id' => $this->ws->id,
        ]);

        $appointment = Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client)
            ->withMasterService($this->masterService)
            ->create([
                'status' => 'cancelled',
                'start_time' => Carbon::tomorrow()->setTime(14, 0),
                'duration' => 60,
            ]);

        $opp1 = $this->service->createFromFreedWindow(...$this->validArgs([
            'originEventId' => (string) Str::uuid(),
            'sourceAppointmentId' => $appointment->id,
        ]));

        $opp2 = $this->service->createFromFreedWindow(...$this->validArgs([
            'originEventId' => (string) Str::uuid(),
            'sourceAppointmentId' => $appointment->id,
        ]));

        $this->assertNotEquals($opp1->id, $opp2->id);
    }

    // ── DB unique constraint ──────────────────────────────

    public function test_db_unique_constraint_on_origin_event_id(): void
    {
        $originEventId = (string) Str::uuid();

        $this->service->createFromFreedWindow(...$this->validArgs([
            'originEventId' => $originEventId,
        ]));

        $this->expectException(QueryException::class);

        // Direct DB insert bypassing service idempotency
        SlotOpportunity::create([
            'origin_event_id' => $originEventId,
            'chain_id' => (string) Str::uuid(),
            'workspace_id' => $this->ws->id,
            'master_id' => $this->master->id,
            'master_service_id' => $this->masterService->id,
            'source_type' => SlotOpportunitySourceType::Cancellation,
            'status' => SlotOpportunityStatus::Open,
            'start_time' => Carbon::tomorrow()->setTime(15, 0),
            'duration' => 60,
        ]);
    }

    // ── Model casts/relationships ─────────────────────────

    public function test_model_casts_and_relationships(): void
    {
        $opp = $this->service->createFromFreedWindow(...$this->validArgs());

        $this->assertInstanceOf(SlotOpportunitySourceType::class, $opp->source_type);
        $this->assertInstanceOf(SlotOpportunityStatus::class, $opp->status);
        $this->assertInstanceOf(\Carbon\CarbonImmutable::class, $opp->start_time);
        $this->assertIsInt($opp->duration);
        $this->assertInstanceOf(Workspace::class, $opp->workspace);
        $this->assertInstanceOf(User::class, $opp->master);
        $this->assertInstanceOf(MasterService::class, $opp->masterService);
    }
}
