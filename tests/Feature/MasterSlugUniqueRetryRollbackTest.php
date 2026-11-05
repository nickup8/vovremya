<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Services\MasterSlugCreateRetry;
use App\Services\WorkspaceService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * N10: откат без внешней тестовой транзакции — реальные COMMIT/ROLLBACK
 * на PostgreSQL, без savepoint'ов RefreshDatabase.
 */
class MasterSlugUniqueRetryRollbackTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Без обёртки транзакцией: каждый DB::transaction — настоящий
     * BEGIN/COMMIT/ROLLBACK, а не savepoint.
     */
    protected $connectionsToTransact = [];

    /** @var list<string> */
    private array $createdUserIds = [];

    /** @var list<string> */
    private array $createdWorkspaceIds = [];

    protected function tearDown(): void
    {
        User::query()
            ->whereIn('id', $this->createdUserIds)
            ->update(['workspace_id' => null]);

        Workspace::query()
            ->whereKey($this->createdWorkspaceIds)
            ->delete();

        User::query()
            ->whereIn('id', $this->createdUserIds)
            ->delete();

        $this->createdUserIds = [];
        $this->createdWorkspaceIds = [];

        parent::tearDown();
    }

    public function test_exhaustion_leaves_no_partial_users_or_workspaces_without_outer_transaction(): void
    {
        $this->trackUser(User::factory()->create([
            'master_slug' => 'rollback-taken',
            'phone' => '79000000901',
        ]));

        $usersBefore = User::query()->count();
        $workspacesBefore = Workspace::query()->count();
        $attempts = 0;

        try {
            app(MasterSlugCreateRetry::class)->run(
                function () use (&$attempts): string {
                    $attempts++;

                    return 'rollback-taken';
                },
                function (string $slug): User {
                    $created = User::create([
                        'name' => 'Rollback Exhausted',
                        'phone' => '79000000903',
                        'is_master' => true,
                        'master_slug' => $slug,
                        'pdn_consent_at' => now(),
                        'pdn_consent_version' => 'v-test',
                    ]);
                    $this->createdUserIds[] = (string) $created->id;

                    $ws = app(WorkspaceService::class)->createForUser($created);
                    $this->createdWorkspaceIds[] = (string) $ws->id;

                    return $created;
                },
            );
            $this->fail('Expected UniqueConstraintViolationException');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertTrue(MasterSlugCreateRetry::isMasterSlugUniqueViolation($e));
        }

        $this->assertSame(MasterSlugCreateRetry::MAX_ATTEMPTS, $attempts);
        $this->assertSame($usersBefore, User::query()->count());
        $this->assertSame($workspacesBefore, Workspace::query()->count());
        $this->assertSame(
            0,
            User::query()->where('phone', '79000000903')->count(),
        );
        $this->assertSame(
            0,
            Workspace::query()->where('name', 'Студия Rollback Exhausted')->count(),
        );
    }

    public function test_successful_retry_after_rollback_creates_exactly_one_user_and_workspace(): void
    {
        $this->trackUser(User::factory()->create([
            'master_slug' => 'rollback-taken',
            'phone' => '79000000901',
        ]));

        $usersBefore = User::query()->count();
        $workspacesBefore = Workspace::query()->count();
        $attempts = 0;
        $failedAttemptLeftRow = null;

        $user = app(MasterSlugCreateRetry::class)->run(
            function () use (&$attempts, &$failedAttemptLeftRow): string {
                $attempts++;

                if ($attempts === 1) {
                    return 'rollback-taken';
                }

                // Первая попытка уже откатилась — до второй generate
                // в БД не должно остаться строки неудавшегося INSERT.
                $failedAttemptLeftRow = User::query()
                    ->where('phone', '79000000904')
                    ->count();

                return 'rollback-free';
            },
            function (string $slug): User {
                $created = User::create([
                    'name' => 'Rollback Success',
                    'phone' => '79000000904',
                    'is_master' => true,
                    'master_slug' => $slug,
                    'pdn_consent_at' => now(),
                    'pdn_consent_version' => 'v-test',
                ]);
                $this->createdUserIds[] = (string) $created->id;

                $ws = app(WorkspaceService::class)->createForUser($created);
                $this->createdWorkspaceIds[] = (string) $ws->id;

                return $created;
            },
        );

        $this->assertSame(2, $attempts);
        $this->assertSame(
            0,
            $failedAttemptLeftRow,
            'Failed attempt must roll back its INSERT before the next try',
        );
        $this->assertSame('rollback-free', $user->fresh()->master_slug);
        $this->assertSame($usersBefore + 1, User::query()->count());
        $this->assertSame($workspacesBefore + 1, Workspace::query()->count());
        $this->assertSame(
            1,
            User::query()->where('phone', '79000000904')->count(),
        );
        $this->assertSame(
            1,
            Workspace::query()->whereKey($user->workspace_id)->count(),
        );
    }

    private function trackUser(User $user): User
    {
        $this->createdUserIds[] = (string) $user->id;

        if ($user->workspace_id) {
            $this->createdWorkspaceIds[] = (string) $user->workspace_id;
        }

        return $user;
    }
}
