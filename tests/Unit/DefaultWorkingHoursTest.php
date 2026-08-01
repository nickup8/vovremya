<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\WorkingHour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DefaultWorkingHoursTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_default_working_hours_creates_exactly_7_records(): void
    {
        $master = User::factory()->master()->create();

        $master->createDefaultWorkingHours();

        $this->assertSame(7, $master->workingHours()->count());
    }

    public function test_create_default_working_hours_is_idempotent(): void
    {
        $master = User::factory()->master()->create();

        $master->createDefaultWorkingHours();
        $master->createDefaultWorkingHours();

        $this->assertSame(7, $master->workingHours()->count());
    }

    public function test_observer_created_gives_master_working_hours(): void
    {
        $master = User::factory()->master()->create();

        $this->assertSame(7, $master->workingHours()->count());
    }

    public function test_observer_updated_gives_hours_when_becoming_master(): void
    {
        $user = User::factory()->create(['is_master' => false]);

        $this->assertSame(0, $user->workingHours()->count());

        $user->update(['is_master' => true]);

        $this->assertSame(7, $user->workingHours()->count());
    }

    public function test_observer_updated_does_not_duplicate_on_repeated_update(): void
    {
        $user = User::factory()->create(['is_master' => false]);

        $user->update(['is_master' => true]);
        $user->update(['name' => 'Updated Name']);

        $this->assertSame(7, $user->workingHours()->count());
    }

    public function test_non_master_does_not_get_working_hours(): void
    {
        $user = User::factory()->create(['is_master' => false]);

        $this->assertSame(0, $user->workingHours()->count());
    }

    public function test_backfill_command_creates_hours_for_master_without_them(): void
    {
        $master = User::factory()->master()->create();
        WorkingHour::where('user_id', $master->id)->delete();

        $this->artisan('working-hours:backfill')->assertExitCode(0);

        $this->assertSame(7, $master->fresh()->workingHours()->count());
    }

    public function test_backfill_command_skips_master_who_already_has_hours(): void
    {
        $master = User::factory()->master()->create();

        $this->artisan('working-hours:backfill')->assertExitCode(0);

        $this->assertSame(7, $master->workingHours()->count());
    }

    public function test_backfill_dry_run_creates_nothing(): void
    {
        $master = User::factory()->master()->create();
        WorkingHour::where('user_id', $master->id)->delete();

        $this->artisan('working-hours:backfill', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, $master->fresh()->workingHours()->count());
    }
}
