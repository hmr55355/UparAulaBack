<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_delete_their_own_account(): void
    {
        $user = User::factory()->create();
        $user->createToken('uparaula');

        $this->actingAs($user, 'sanctum')->deleteJson('/api/auth/me')->assertOk();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
