<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\MasterService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CancelUnpaidAppointmentsTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    private MasterService $masterService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->master = User::factory()->master()->create([
            'deposit_timeout' => 15,
        ]);

        $workspace = \App\Models\Workspace::create(['name' => 'WS Cancel', 'owner_id' => $this->master->id]);
        $catalog = \App\Models\ServiceCatalog::create(['workspace_id' => $workspace->id, 'title' => 'Услуга', 'base_price' => 1000, 'base_duration' => 60]);
        $this->masterService = MasterService::create(['master_id' => $this->master->id, 'catalog_id' => $catalog->id, 'is_active' => true]);
    }

    private function createPendingAppointment(int $minutesAgo): Appointment
    {
        $client = Client::factory()->create(['user_id' => $this->master->id]);

        return Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client)
            ->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::PendingPayment,
                'created_at' => Carbon::now()->subMinutes($minutesAgo),
            ]);
    }

    public function test_pending_payment_older_than_timeout_is_cancelled(): void
    {
        $appointment = $this->createPendingAppointment(20);

        $this->artisan('appointments:cancel-unpaid');

        $this->assertEquals(AppointmentStatus::Cancelled, $appointment->fresh()->status);
    }

    public function test_pending_payment_younger_than_timeout_stays(): void
    {
        $appointment = $this->createPendingAppointment(5);

        $this->artisan('appointments:cancel-unpaid');

        $this->assertEquals(AppointmentStatus::PendingPayment, $appointment->fresh()->status);
    }

    public function test_booked_appointment_is_not_touched(): void
    {
        $client = Client::factory()->create(['user_id' => $this->master->id]);

        $appointment = Appointment::factory()
            ->forMaster($this->master)
            ->forClient($client)
            ->withMasterService($this->masterService)
            ->create([
                'status' => AppointmentStatus::Booked,
                'created_at' => Carbon::now()->subMinutes(100),
            ]);

        $this->artisan('appointments:cancel-unpaid');

        $this->assertEquals(AppointmentStatus::Booked, $appointment->fresh()->status);
    }

    public function test_short_deposit_timeout_cancels_quickly(): void
    {
        $this->master->update(['deposit_timeout' => 1]);

        $appointment = $this->createPendingAppointment(2);

        $this->artisan('appointments:cancel-unpaid');

        $this->assertEquals(AppointmentStatus::Cancelled, $appointment->fresh()->status);
    }

    public function test_different_masters_have_different_thresholds(): void
    {
        $master1 = User::factory()->master()->create(['deposit_timeout' => 10]);
        $master2 = User::factory()->master()->create(['deposit_timeout' => 30]);

        $ws1 = \App\Models\Workspace::create(['name' => 'WS1', 'owner_id' => $master1->id]);
        $cat1 = \App\Models\ServiceCatalog::create(['workspace_id' => $ws1->id, 'title' => 'Услуга1', 'base_price' => 1000, 'base_duration' => 60]);
        $ms1 = MasterService::create(['master_id' => $master1->id, 'catalog_id' => $cat1->id, 'is_active' => true]);

        $ws2 = \App\Models\Workspace::create(['name' => 'WS2', 'owner_id' => $master2->id]);
        $cat2 = \App\Models\ServiceCatalog::create(['workspace_id' => $ws2->id, 'title' => 'Услуга2', 'base_price' => 1000, 'base_duration' => 60]);
        $ms2 = MasterService::create(['master_id' => $master2->id, 'catalog_id' => $cat2->id, 'is_active' => true]);

        $client1 = Client::factory()->create(['user_id' => $master1->id]);
        $client2 = Client::factory()->create(['user_id' => $master2->id]);

        // 15 минут назад: для master1 (10 мин) → просрочена, для master2 (30 мин) → нет
        $appt1 = Appointment::factory()
            ->forMaster($master1)
            ->forClient($client1)
            ->withMasterService($ms1)
            ->create([
                'status' => AppointmentStatus::PendingPayment,
                'created_at' => Carbon::now()->subMinutes(15),
            ]);

        $appt2 = Appointment::factory()
            ->forMaster($master2)
            ->forClient($client2)
            ->withMasterService($ms2)
            ->create([
                'status' => AppointmentStatus::PendingPayment,
                'created_at' => Carbon::now()->subMinutes(15),
            ]);

        $this->artisan('appointments:cancel-unpaid');

        $this->assertEquals(AppointmentStatus::Cancelled, $appt1->fresh()->status);
        $this->assertEquals(AppointmentStatus::PendingPayment, $appt2->fresh()->status);
    }
}
