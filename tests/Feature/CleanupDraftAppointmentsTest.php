<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CleanupDraftAppointmentsTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    protected function setUp(): void
    {
        parent::setUp();
        $this->master = User::factory()->master()->create();
    }

    private function createDraft(string $status, int $minutesAgo, ?string $clientId = null): Appointment
    {
        return Appointment::factory()
            ->forMaster($this->master)
            ->create([
                'client_id' => $clientId,
                'status' => $status,
                'created_at' => Carbon::now()->subMinutes($minutesAgo),
            ]);
    }

    // ── Stale Booked + null client → Cancelled ────────────

    public function test_stale_booked_null_client_is_cancelled(): void
    {
        $appt = $this->createDraft(AppointmentStatus::Booked->value, 20);

        $this->artisan('appointments:cleanup-drafts')->assertExitCode(0);

        $this->assertEquals(AppointmentStatus::Cancelled, $appt->fresh()->status);
    }

    // ── Stale PendingPayment + null client → Cancelled ────

    public function test_stale_pending_payment_null_client_is_cancelled(): void
    {
        $appt = $this->createDraft(AppointmentStatus::PendingPayment->value, 20);

        $this->artisan('appointments:cleanup-drafts')->assertExitCode(0);

        $this->assertEquals(AppointmentStatus::Cancelled, $appt->fresh()->status);
    }

    // ── Stale Paid + null client → remains Paid ───────────

    public function test_stale_paid_null_client_remains_paid(): void
    {
        $appt = $this->createDraft(AppointmentStatus::Paid->value, 20);

        $this->artisan('appointments:cleanup-drafts')->assertExitCode(0);

        $this->assertEquals(AppointmentStatus::Paid, $appt->fresh()->status);
    }

    // ── Stale Prepaid + null client → remains Prepaid ─────

    public function test_stale_prepaid_null_client_remains_prepaid(): void
    {
        $appt = $this->createDraft(AppointmentStatus::Prepaid->value, 20);

        $this->artisan('appointments:cleanup-drafts')->assertExitCode(0);

        $this->assertEquals(AppointmentStatus::Prepaid, $appt->fresh()->status);
    }

    // ── Stale NoShow + null client → remains NoShow ───────

    public function test_stale_no_show_null_client_remains_no_show(): void
    {
        $appt = $this->createDraft(AppointmentStatus::NoShow->value, 20);

        $this->artisan('appointments:cleanup-drafts')->assertExitCode(0);

        $this->assertEquals(AppointmentStatus::NoShow, $appt->fresh()->status);
    }

    // ── Fresh Booked + null client → remains Booked ───────

    public function test_fresh_booked_null_client_remains_booked(): void
    {
        $appt = $this->createDraft(AppointmentStatus::Booked->value, 5);

        $this->artisan('appointments:cleanup-drafts')->assertExitCode(0);

        $this->assertEquals(AppointmentStatus::Booked, $appt->fresh()->status);
    }

    // ── Stale Booked + valid client → remains Booked ──────

    public function test_stale_booked_with_client_remains_booked(): void
    {
        $client = \App\Models\Client::factory()->create(['user_id' => $this->master->id]);

        $appt = Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client)
            ->create([
                'status' => AppointmentStatus::Booked->value,
                'created_at' => Carbon::now()->subMinutes(20),
            ]);

        $this->artisan('appointments:cleanup-drafts')->assertExitCode(0);

        $this->assertEquals(AppointmentStatus::Booked, $appt->fresh()->status);
    }

    // ── Stale Cancelled + null client → remains Cancelled ─

    public function test_stale_cancelled_null_client_remains_cancelled(): void
    {
        $appt = $this->createDraft(AppointmentStatus::Cancelled->value, 20);

        $this->artisan('appointments:cleanup-drafts')->assertExitCode(0);

        $this->assertEquals(AppointmentStatus::Cancelled, $appt->fresh()->status);
    }

    // ── Stale Booked + null client + provider=admin ───────

    public function test_stale_booked_null_client_with_provider_cancelled(): void
    {
        $appt = Appointment::factory()
            ->forMaster($this->master)
            ->provider('admin')
            ->create([
                'client_id' => null,
                'status' => AppointmentStatus::Booked->value,
                'created_at' => Carbon::now()->subMinutes(20),
            ]);

        $this->artisan('appointments:cleanup-drafts')->assertExitCode(0);

        $this->assertEquals(AppointmentStatus::Cancelled, $appt->fresh()->status);
    }

    // ── Idempotency ───────────────────────────────────────

    public function test_idempotent_second_run(): void
    {
        $appt = $this->createDraft(AppointmentStatus::Booked->value, 20);

        $this->artisan('appointments:cleanup-drafts')->assertExitCode(0);
        $this->assertEquals(AppointmentStatus::Cancelled, $appt->fresh()->status);

        $this->artisan('appointments:cleanup-drafts')->assertExitCode(0);
        $this->assertEquals(AppointmentStatus::Cancelled, $appt->fresh()->status);
    }

    // ── No matching rows ──────────────────────────────────

    public function test_no_matching_rows_succeeds(): void
    {
        $this->artisan('appointments:cleanup-drafts')->assertExitCode(0);
    }
}
