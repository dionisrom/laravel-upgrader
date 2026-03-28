<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class UserTest extends TestCase
{
    public function test_user_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $user->delete();

        // assertDeleted removed in L10 — should become assertModelMissing
        $this->assertDeleted($user);
    }

    public function test_table_assert_deleted(): void
    {
        // Table-based assertDeleted — should NOT be converted
        $this->assertDeleted('users', ['email' => 'test@example.com']);
    }
}
