<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_teacher_can_register(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Docente Prueba',
            'email' => 'docente@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()->assertJsonStructure(['user' => ['id', 'name', 'email'], 'token']);
        $this->assertDatabaseHas('users', ['email' => 'docente@example.com']);
    }

    public function test_registration_requires_matching_password_confirmation(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Docente Prueba',
            'email' => 'docente@example.com',
            'password' => 'password123',
            'password_confirmation' => 'otra-clave',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_a_teacher_can_login_with_correct_credentials(): void
    {
        User::factory()->create([
            'email' => 'docente@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'docente@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk()->assertJsonStructure(['user', 'token']);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'docente@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'docente@example.com',
            'password' => 'incorrecta',
        ]);

        $response->assertUnprocessable();
    }

    public function test_authenticated_user_can_fetch_me(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/auth/me');

        $response->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_guest_cannot_access_me(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }
}
