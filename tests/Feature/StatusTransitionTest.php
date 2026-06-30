<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use App\Services\AppointmentStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    private User $master;
    private Service $service;
    private AppointmentStatusService $statusService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->master = User::factory()->master()->create();
        $this->service = Service::factory()->create(['user_id' => $this->master->id]);
        $this->statusService = app(AppointmentStatusService::class);
    }

    private function makeAppointment(AppointmentStatus $status): Appointment
    {
        return Appointment::factory()
            ->forMaster($this->master)
            ->withService($this->service)
            ->create(['status' => $status]);
    }

    // ─── Booked ───

    public function test_booked_to_paid(): void
    {
        $a = $this->makeAppointment(AppointmentStatus::Booked);
        $this->statusService->transition($a, AppointmentStatus::Paid);
        $this->assertEquals(AppointmentStatus::Paid, $a->fresh()->status);
    }

    public function test_booked_to_no_show(): void
    {
        $a = $this->makeAppointment(AppointmentStatus::Booked);
        $this->statusService->transition($a, AppointmentStatus::NoShow);
        $this->assertEquals(AppointmentStatus::NoShow, $a->fresh()->status);
    }

    public function test_booked_to_cancelled(): void
    {
        $a = $this->makeAppointment(AppointmentStatus::Booked);
        $this->statusService->transition($a, AppointmentStatus::Cancelled);
        $this->assertEquals(AppointmentStatus::Cancelled, $a->fresh()->status);
    }

    public function test_booked_to_booked_throws(): void
    {
        $a = $this->makeAppointment(AppointmentStatus::Booked);
        $this->expectException(InvalidStatusTransitionException::class);
        $this->statusService->transition($a, AppointmentStatus::Booked);
    }

    // ─── NoShow ───

    public function test_no_show_to_booked(): void
    {
        $a = $this->makeAppointment(AppointmentStatus::NoShow);
        $this->statusService->transition($a, AppointmentStatus::Booked);
        $this->assertEquals(AppointmentStatus::Booked, $a->fresh()->status);
    }

    public function test_no_show_to_paid(): void
    {
        $a = $this->makeAppointment(AppointmentStatus::NoShow);
        $this->statusService->transition($a, AppointmentStatus::Paid);
        $this->assertEquals(AppointmentStatus::Paid, $a->fresh()->status);
    }

    public function test_no_show_to_cancelled(): void
    {
        $a = $this->makeAppointment(AppointmentStatus::NoShow);
        $this->statusService->transition($a, AppointmentStatus::Cancelled);
        $this->assertEquals(AppointmentStatus::Cancelled, $a->fresh()->status);
    }

    public function test_no_show_to_no_show_throws(): void
    {
        $a = $this->makeAppointment(AppointmentStatus::NoShow);
        $this->expectException(InvalidStatusTransitionException::class);
        $this->statusService->transition($a, AppointmentStatus::NoShow);
    }

    // ─── Paid ───

    public function test_paid_to_no_show(): void
    {
        $a = $this->makeAppointment(AppointmentStatus::Paid);
        $this->statusService->transition($a, AppointmentStatus::NoShow);
        $this->assertEquals(AppointmentStatus::NoShow, $a->fresh()->status);
    }

    public function test_paid_to_booked_throws(): void
    {
        $a = $this->makeAppointment(AppointmentStatus::Paid);
        $this->expectException(InvalidStatusTransitionException::class);
        $this->statusService->transition($a, AppointmentStatus::Booked);
    }

    public function test_paid_to_paid_throws(): void
    {
        $a = $this->makeAppointment(AppointmentStatus::Paid);
        $this->expectException(InvalidStatusTransitionException::class);
        $this->statusService->transition($a, AppointmentStatus::Paid);
    }

    public function test_paid_to_cancelled_throws(): void
    {
        $a = $this->makeAppointment(AppointmentStatus::Paid);
        $this->expectException(InvalidStatusTransitionException::class);
        $this->statusService->transition($a, AppointmentStatus::Cancelled);
    }

    // ─── Cancelled ───

    public function test_cancelled_to_booked(): void
    {
        $a = $this->makeAppointment(AppointmentStatus::Cancelled);
        $this->statusService->transition($a, AppointmentStatus::Booked);
        $this->assertEquals(AppointmentStatus::Booked, $a->fresh()->status);
    }

    public function test_cancelled_to_paid_throws(): void
    {
        $a = $this->makeAppointment(AppointmentStatus::Cancelled);
        $this->expectException(InvalidStatusTransitionException::class);
        $this->statusService->transition($a, AppointmentStatus::Paid);
    }

    public function test_cancelled_to_no_show_throws(): void
    {
        $a = $this->makeAppointment(AppointmentStatus::Cancelled);
        $this->expectException(InvalidStatusTransitionException::class);
        $this->statusService->transition($a, AppointmentStatus::NoShow);
    }

    public function test_cancelled_to_cancelled_throws(): void
    {
        $a = $this->makeAppointment(AppointmentStatus::Cancelled);
        $this->expectException(InvalidStatusTransitionException::class);
        $this->statusService->transition($a, AppointmentStatus::Cancelled);
    }

    // ─── NoShow + Reschedule → Booked ───

    public function test_no_show_reschedule_becomes_booked(): void
    {
        $client = Client::factory()->create(['user_id' => $this->master->id]);
        $a = $this->makeAppointment(AppointmentStatus::NoShow);
        $a->update(['client_id' => $client->id]);

        $this->statusService->transition($a, AppointmentStatus::Booked);

        $this->assertEquals(AppointmentStatus::Booked, $a->fresh()->status);
    }

    // ─── canTransitionTo unit checks ───

    public function test_can_transition_to_returns_true_for_valid(): void
    {
        $this->assertTrue(AppointmentStatus::Booked->canTransitionTo(AppointmentStatus::Paid));
        $this->assertTrue(AppointmentStatus::NoShow->canTransitionTo(AppointmentStatus::Booked));
        $this->assertTrue(AppointmentStatus::Paid->canTransitionTo(AppointmentStatus::NoShow));
        $this->assertTrue(AppointmentStatus::Cancelled->canTransitionTo(AppointmentStatus::Booked));
    }

    public function test_can_transition_to_returns_false_for_invalid(): void
    {
        $this->assertFalse(AppointmentStatus::Booked->canTransitionTo(AppointmentStatus::Booked));
        $this->assertFalse(AppointmentStatus::Paid->canTransitionTo(AppointmentStatus::Paid));
        $this->assertFalse(AppointmentStatus::Paid->canTransitionTo(AppointmentStatus::Cancelled));
        $this->assertFalse(AppointmentStatus::Cancelled->canTransitionTo(AppointmentStatus::Paid));
    }

    // ─── Labels ───

    public function test_labels(): void
    {
        $this->assertEquals('Записан', AppointmentStatus::Booked->label());
        $this->assertEquals('Неявка', AppointmentStatus::NoShow->label());
        $this->assertEquals('Оплачен', AppointmentStatus::Paid->label());
        $this->assertEquals('Отменён', AppointmentStatus::Cancelled->label());
    }
}
