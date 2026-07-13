<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\InstitutionTeacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstitutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_institution_makes_the_creator_an_active_admin(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/institutions', [
            'name' => 'Colegio de Prueba',
            'city' => 'Bogotá',
            'department' => 'Cundinamarca',
            'academic_year' => 2026,
            'academic_year_start' => '2026-01-20',
            'academic_year_end' => '2026-11-28',
        ]);

        $response->assertCreated()->assertJsonPath('data.my_role', 'admin');

        $institutionId = $response->json('data.id');

        $this->assertDatabaseHas('institution_teachers', [
            'institution_id' => $institutionId,
            'user_id' => $user->id,
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->assertDatabaseCount('periods', 4);
    }

    public function test_a_teacher_cannot_manage_another_institutions_teachers(): void
    {
        $admin = User::factory()->create();
        $institution = Institution::create([
            'name' => 'Colegio Ajeno', 'city' => 'Cali', 'department' => 'Valle', 'created_by' => $admin->id,
        ]);
        InstitutionTeacher::create([
            'institution_id' => $institution->id, 'user_id' => $admin->id, 'role' => 'admin', 'status' => 'active',
        ]);

        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider, 'sanctum')->getJson("/api/institutions/{$institution->id}/teachers");

        $response->assertForbidden();
    }

    public function test_a_teacher_member_cannot_invite_other_teachers(): void
    {
        $admin = User::factory()->create();
        $institution = Institution::create([
            'name' => 'Colegio Demo', 'city' => 'Cali', 'department' => 'Valle', 'created_by' => $admin->id,
        ]);
        InstitutionTeacher::create([
            'institution_id' => $institution->id, 'user_id' => $admin->id, 'role' => 'admin', 'status' => 'active',
        ]);

        $teacher = User::factory()->create();
        InstitutionTeacher::create([
            'institution_id' => $institution->id, 'user_id' => $teacher->id, 'role' => 'teacher', 'status' => 'active',
        ]);

        $invitee = User::factory()->create(['email' => 'nuevo@example.com']);

        $response = $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/institutions/{$institution->id}/teachers/invite", ['email' => $invitee->email]);

        $response->assertForbidden();
    }

    public function test_an_admin_can_create_a_teacher_account_directly_and_it_can_log_in(): void
    {
        $admin = User::factory()->create();
        $institution = Institution::create([
            'name' => 'Colegio Demo', 'city' => 'Cali', 'department' => 'Valle', 'created_by' => $admin->id,
        ]);
        InstitutionTeacher::create([
            'institution_id' => $institution->id, 'user_id' => $admin->id, 'role' => 'admin', 'status' => 'active',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/institutions/{$institution->id}/teachers", [
            'name' => 'Laura Gómez',
            'email' => 'laura.gomez@example.com',
            'password' => 'password123',
            'role' => 'teacher',
        ]);

        $response->assertCreated()->assertJsonPath('data.role', 'teacher')->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('users', ['email' => 'laura.gomez@example.com']);
        $this->assertDatabaseHas('institution_teachers', [
            'institution_id' => $institution->id,
            'role' => 'teacher',
            'status' => 'active',
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => 'laura.gomez@example.com',
            'password' => 'password123',
        ]);

        $login->assertOk();
    }

    public function test_a_teacher_member_cannot_create_other_teacher_accounts(): void
    {
        $admin = User::factory()->create();
        $institution = Institution::create([
            'name' => 'Colegio Demo', 'city' => 'Cali', 'department' => 'Valle', 'created_by' => $admin->id,
        ]);
        InstitutionTeacher::create([
            'institution_id' => $institution->id, 'user_id' => $admin->id, 'role' => 'admin', 'status' => 'active',
        ]);

        $teacher = User::factory()->create();
        InstitutionTeacher::create([
            'institution_id' => $institution->id, 'user_id' => $teacher->id, 'role' => 'teacher', 'status' => 'active',
        ]);

        $response = $this->actingAs($teacher, 'sanctum')->postJson("/api/institutions/{$institution->id}/teachers", [
            'name' => 'Alguien',
            'email' => 'alguien@example.com',
            'password' => 'password123',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'alguien@example.com']);
    }

    public function test_creating_a_teacher_with_a_duplicate_email_is_rejected(): void
    {
        $admin = User::factory()->create();
        $institution = Institution::create([
            'name' => 'Colegio Demo', 'city' => 'Cali', 'department' => 'Valle', 'created_by' => $admin->id,
        ]);
        InstitutionTeacher::create([
            'institution_id' => $institution->id, 'user_id' => $admin->id, 'role' => 'admin', 'status' => 'active',
        ]);

        $existing = User::factory()->create(['email' => 'ya.existe@example.com']);

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/institutions/{$institution->id}/teachers", [
            'name' => 'Duplicado',
            'email' => $existing->email,
            'password' => 'password123',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }
}
