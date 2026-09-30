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

    public function test_a_user_can_upload_view_replace_and_remove_their_avatar(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $user = \App\Models\User::factory()->create();

        $first = $this->actingAs($user, 'sanctum')->post('/api/auth/profile', [
            '_method' => 'PUT', 'avatar' => \Illuminate\Http\UploadedFile::fake()->image('yo.png', 200, 200),
        ], ['Accept' => 'application/json'])->assertOk()->json('data.avatar');
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($first);
        $this->actingAs($user, 'sanctum')->get('/api/auth/avatar')->assertOk();

        $second = $this->actingAs($user, 'sanctum')->post('/api/auth/profile', [
            '_method' => 'PUT', 'avatar' => \Illuminate\Http\UploadedFile::fake()->image('nueva.png', 200, 200),
        ], ['Accept' => 'application/json'])->assertOk()->json('data.avatar');
        \Illuminate\Support\Facades\Storage::disk('local')->assertMissing($first);
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($second);

        $this->actingAs($user, 'sanctum')->putJson('/api/auth/profile', ['remove_avatar' => true])
            ->assertOk()->assertJsonPath('data.avatar', null);
        \Illuminate\Support\Facades\Storage::disk('local')->assertMissing($second);
        $this->actingAs($user, 'sanctum')->getJson('/api/auth/avatar')->assertNotFound();
    }

}
