<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Group;
use App\Models\InstitutionTeacher;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $institutionId;

    private Group $a;

    private Group $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->institutionId = $this->actingAs($this->admin, 'sanctum')->postJson('/api/institutions', [
            'name' => 'Colegio', 'city' => 'Valledupar', 'department' => 'Cesar',
            'academic_year' => 2026, 'academic_year_start' => '2026-01-20', 'academic_year_end' => '2026-11-28',
        ])->json('data.id');
        $yearId = AcademicYear::value('id');
        $this->a = Group::create(['institution_id' => $this->institutionId, 'academic_year_id' => $yearId, 'name' => '1001', 'grade_level' => '10']);
        $this->b = Group::create(['institution_id' => $this->institutionId, 'academic_year_id' => $yearId, 'name' => '1002', 'grade_level' => '10']);
    }

    private function createStudent(array $data = []): int
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson('/api/students', [
            'group_id' => $this->a->id, 'first_name' => 'Ana', 'last_name' => 'Díaz',
            'document_type' => 'TI', 'document_number' => '1065000111', 'birthdate' => '2010-05-04', 'gender' => 'femenino',
            ...$data,
        ])->assertCreated()->json('data.id');
    }

    public function test_an_admin_creates_and_edits_a_student_and_duplicates_are_rejected(): void
    {
        $id = $this->createStudent();
        $this->assertEquals(1, $this->a->fresh()->student_count);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/students', [
            'group_id' => $this->a->id, 'first_name' => 'Otra', 'last_name' => 'Persona', 'document_number' => '1065000111',
        ])->assertStatus(422);

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/students/{$id}", ['phone' => '3001234567', 'address' => 'Calle 1'])
            ->assertOk()->assertJsonPath('data.phone', '3001234567');
    }

    public function test_withdraw_transfer_and_enroll_again_keep_the_history(): void
    {
        $id = $this->createStudent();

        // Traslado 1001 → 1002: la matrícula vieja queda "trasladado", se abre otra.
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/students/{$id}/transfer", [
            'from_group_id' => $this->a->id, 'to_group_id' => $this->b->id, 'date' => '2026-04-01',
        ])->assertCreated();
        $this->assertDatabaseHas('student_groups', ['student_id' => $id, 'group_id' => $this->a->id, 'status' => 'trasladado']);
        $this->assertDatabaseHas('student_groups', ['student_id' => $id, 'group_id' => $this->b->id, 'status' => 'activo']);
        $this->assertEquals([0, 1], [$this->a->fresh()->student_count, $this->b->fresh()->student_count]);

        // Retiro.
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/students/{$id}/withdraw", [
            'group_id' => $this->b->id, 'withdrawal_date' => '2026-06-15', 'withdrawal_reason' => 'Cambio de ciudad',
        ])->assertOk()->assertJsonPath('data.status', 'retirado');
        $this->assertEquals(0, $this->b->fresh()->student_count);
        $this->assertFalse(Student::find($id)->is_active);

        // Regresa: se reactiva la matrícula de 1002 (no se duplica).
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/students/{$id}/enroll", [
            'group_id' => $this->b->id, 'enrollment_date' => '2026-08-01',
        ])->assertCreated();
        $this->assertDatabaseCount('student_groups', 2);
        $this->assertEquals(1, $this->b->fresh()->student_count);
        $this->assertTrue(Student::find($id)->is_active);

        // Ya activo este año: matricular en otro grupo exige trasladar.
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/students/{$id}/enroll", [
            'group_id' => $this->a->id, 'enrollment_date' => '2026-08-02',
        ])->assertStatus(422);

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/groups/{$this->a->id}/enrollments")
            ->assertOk()->assertJsonPath('data.0.status', 'trasladado');
    }

    public function test_a_teacher_cannot_manage_students(): void
    {
        $teacher = User::factory()->create();
        InstitutionTeacher::create(['institution_id' => $this->institutionId, 'user_id' => $teacher->id, 'role' => 'teacher', 'status' => 'active']);

        $this->actingAs($teacher, 'sanctum')->postJson('/api/students', [
            'group_id' => $this->a->id, 'first_name' => 'X', 'last_name' => 'Y',
        ])->assertForbidden();
        $this->assertEquals(0, Student::count());
    }

    public function test_only_one_academic_year_is_active(): void
    {
        $second = $this->actingAs($this->admin, 'sanctum')->postJson('/api/academic-years', [
            'institution_id' => $this->institutionId, 'year' => 2027, 'start_date' => '2027-01-20', 'end_date' => '2027-11-28',
        ])->assertCreated()->json('data.id');
        $this->assertEquals(1, AcademicYear::where('is_active', true)->count());

        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/academic-years/{$second}/set-active")->assertOk();
        $this->assertEquals([$second], AcademicYear::where('is_active', true)->pluck('id')->all());

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/academic-years/{$second}", ['end_date' => '2027-12-01'])->assertOk();
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/academic-years/{$second}", ['end_date' => '2026-01-01'])->assertStatus(422);
    }
}
