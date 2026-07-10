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
}
