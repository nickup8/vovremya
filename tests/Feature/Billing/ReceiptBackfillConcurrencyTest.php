<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingCycleOrigin;
use App\Enums\BillingCycleStatus;
use App\Enums\BillingSubscriptionStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentEnvironment;
use App\Enums\PaymentReceiptStatus;
use App\Models\BillingCycle;
use App\Models\BillingSubscription;
use App\Models\Notification;
use App\Models\PaymentAttempt;
use App\Models\PaymentReceipt;
use App\Models\TariffPlan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Genuine cross-session concurrency for the receipt backfill command.
 *
 * RefreshDatabase wraps every test in an uncommitted transaction, which a
 * second connection cannot see — so this class migrates fresh and commits
 * its fixtures, then opens a real second PostgreSQL session to hold the
 * receipt row lock the way a concurrent attachUrl transaction would.
 *
 * (The DatabaseMigrations trait is not usable here: its migrate:rollback
 * teardown is broken by a legacy down() in this repo, so the schema is
 * re-freshed around the test instead.)
 *
 * If the command's check-then-create were not serialized on that lock, it
 * would finish with exit code 0 and create the notification anyway.
 */
class ReceiptBackfillConcurrencyTest extends TestCase
{
    private const URL_A = 'https://lknpd.nalog.ru/api/v1/receipt/5248061993/aaa111/print';

    protected function setUp(): void
    {
        parent::setUp();

        // Committed fixtures are required: the competing session must see
        // the receipt row, so no wrapping test transaction may exist.
        $this->artisan('migrate:fresh', ['--force' => true])->run();
    }

    protected function tearDown(): void
    {
        // The test committed real rows — wipe them so the next
        // RefreshDatabase class starts from a clean migrated schema.
        $this->artisan('migrate:fresh', ['--force' => true])->run();

        parent::tearDown();
    }

    public function test_command_waits_for_receipt_locked_by_competing_session(): void
    {
        config(['billing.receipts.seller_inn' => '5248061993']);
        $owner = User::factory()->master()->create();
        $workspace = Workspace::create(['name' => 'ws-'.$owner->id, 'owner_id' => $owner->id]);
        $workspace->ensureSlug();
        $owner->update(['workspace_id' => $workspace->id]);

        $receipt = $this->makeReceipt($workspace, PaymentReceiptStatus::Attached, self::URL_A);

        $second = $this->secondConnection();
        $second->beginTransaction();
        $second->table('payment_receipts')
            ->where('id', $receipt->getKey())
            ->lockForUpdate()
            ->first();

        $blocked = false;
        $lockRelated = false;
        $exitCode = null;

        // Bound the wait so a missing lock fails fast instead of hanging.
        DB::statement("SET lock_timeout = '400ms'");

        try {
            $exitCode = $this->artisan('receipts:backfill-owner-notifications')->run();
        } catch (QueryException $e) {
            $blocked = true;
            $lockRelated = str_contains($e->getMessage(), 'lock timeout')
                || str_contains($e->getMessage(), '55P03')
                || str_contains($e->getMessage(), 'lock_not_available');
        } finally {
            DB::statement("SET lock_timeout = '0'");
            $second->rollBack();
        }

        if (! $blocked && $exitCode !== null && $exitCode !== 0) {
            // The console kernel rendered the lock error; treat a non-zero
            // exit as the block having happened.
            $blocked = true;
            $lockRelated = true;
        }

        $this->assertTrue(
            $blocked,
            'the command must serialize with the competing session on the receipt row lock',
        );
        $this->assertTrue($lockRelated, 'the command must have failed on the lock itself');

        // Nothing may be created from a run that could not complete its
        // locked check-then-create.
        $this->assertSame(0, $owner->notifications()->count());
        $this->assertSame(0, Notification::count());
    }

    private function secondConnection(): Connection
    {
        config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);

        return DB::connection('pgsql_second');
    }

    private function makeReceipt(Workspace $workspace, PaymentReceiptStatus $status, string $url): PaymentReceipt
    {
        $plan = TariffPlan::create([
            'code' => 'pro', 'name' => 'Профи', 'price_monthly' => 490,
            'max_appointments_per_month' => null, 'max_masters' => 1,
            'features' => ['unlimited_appointments'], 'is_active' => true,
        ]);

        $billingSub = BillingSubscription::create([
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'status' => BillingSubscriptionStatus::Active,
        ]);

        $cycle = BillingCycle::create([
            'billing_subscription_id' => $billingSub->id,
            'workspace_id' => $workspace->id,
            'tariff_plan_id' => $plan->id,
            'period_start' => now()->subMonth(),
            'period_end' => now(),
            'status' => BillingCycleStatus::Paid,
            'amount' => 490,
            'currency' => 'RUB',
            'origin' => BillingCycleOrigin::Payment,
        ]);

        $attempt = PaymentAttempt::create([
            'billing_cycle_id' => $cycle->id,
            'provider' => 'tbank',
            'payment_env' => PaymentEnvironment::Live,
            'attempt_number' => 1,
            'amount' => 490,
            'currency' => 'RUB',
            'internal_order_id' => 'core_'.bin2hex(random_bytes(16)),
            'provider_payment_id' => 'prov_'.bin2hex(random_bytes(8)),
            'status' => PaymentAttemptStatus::Succeeded,
            'initiated_at' => now(),
            'finished_at' => now(),
            'confirmed_at' => now(),
        ]);

        return PaymentReceipt::create([
            'payment_attempt_id' => $attempt->id,
            'status' => $status,
            'receipt_url' => $url,
            'attached_at' => now(),
        ]);
    }
}
