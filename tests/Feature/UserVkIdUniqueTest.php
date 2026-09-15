<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserVkIdUniqueTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_users_with_null_vk_id_coexist(): void
    {
        $a = User::factory()->create(['vk_id' => null]);
        $b = User::factory()->create(['vk_id' => null]);

        $this->assertDatabaseCount('users', 2);
        $this->assertNull($a->fresh()->vk_id);
        $this->assertNull($b->fresh()->vk_id);
    }

    public function test_duplicate_non_null_vk_id_violates_unique_constraint(): void
    {
        User::factory()->create(['vk_id' => 'vk_12345']);

        $this->expectException(QueryException::class);

        User::factory()->create(['vk_id' => 'vk_12345']);
    }
}
