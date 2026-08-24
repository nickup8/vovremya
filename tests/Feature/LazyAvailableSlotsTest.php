<?php

namespace Tests\Feature;

use App\Models\MasterService;
use App\Models\User;
use App\Models\WorkingHour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LazyAvailableSlotsTest extends TestCase
{
    use RefreshDatabase;

    private function createMasterWithSchedule(): User
    {
        $master = User::factory()->master()->create([
            'workspace_id' => null,
            'master_slug' => 'lazy-test-master',
            'is_service_provider' => true,
            'settings' => ['timezone' => 'Europe/Moscow', 'timezone_confirmed' => true],
        ]);

        for ($day = 0; $day <= 6; $day++) {
            WorkingHour::updateOrCreate(
                ['user_id' => $master->id, 'day_of_week' => $day],
                [
                    'start_time' => '09:00',
                    'end_time' => '18:00',
                    'break_start_time' => '13:00',
                    'break_end_time' => '14:00',
                    'is_working' => true,
                ],
            );
        }

        return $master;
    }

    public function test_full_initial_request_succeeds_without_available_slots(): void
    {
        $master = $this->createMasterWithSchedule();
        $service = MasterService::factory()->forMaster($master)->create([
            'duration_override' => 60,
        ]);

        $response = $this->get("/book/{$master->master_slug}");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('booking/widget')
            ->has('services', 1)
            ->has('master')
            ->missing('availableSlots')
        );
    }

    public function test_full_request_with_query_params_succeeds_without_available_slots(): void
    {
        $master = $this->createMasterWithSchedule();
        $service = MasterService::factory()->forMaster($master)->create([
            'duration_override' => 60,
        ]);

        $tomorrow = Carbon::tomorrow('Europe/Moscow')->toDateString();

        $response = $this->get("/book/{$master->master_slug}?service_id={$service->id}&date={$tomorrow}");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('booking/widget')
            ->has('services', 1)
            ->has('master')
            ->where('selectedServiceId', (string) $service->id)
            ->where('selectedDate', $tomorrow)
            ->missing('availableSlots')
        );
    }

    public function test_partial_reload_returns_available_slots(): void
    {
        $master = $this->createMasterWithSchedule();
        $service = MasterService::factory()->forMaster($master)->create([
            'duration_override' => 60,
        ]);

        $tomorrow = Carbon::tomorrow('Europe/Moscow')->toDateString();

        $response = $this->get("/book/{$master->master_slug}");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('booking/widget')
            ->reloadOnly('availableSlots', fn (Assert $reload) => $reload
                ->has('availableSlots')
                ->whereType('availableSlots', 'array')
                ->etc()
            )
        );
    }

    public function test_partial_reload_returns_real_slots_for_free_day(): void
    {
        $master = $this->createMasterWithSchedule();
        $service = MasterService::factory()->forMaster($master)->create([
            'duration_override' => 60,
        ]);

        $futureDate = Carbon::today()->addDays(3)->toDateString();

        $response = $this->get("/book/{$master->master_slug}?service_id={$service->id}&date={$futureDate}");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('booking/widget')
            ->reloadOnly('availableSlots', function (Assert $reload) {
                $reload->has('availableSlots');
                $slots = $reload->toArray()['props']['availableSlots'];
                $this->assertIsArray($slots);
                $this->assertNotEmpty($slots, 'Available slots should not be empty for a free working day');
                $this->assertContains('09:00', $slots);
            })
        );
    }
}
