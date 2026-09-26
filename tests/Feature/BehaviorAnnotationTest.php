<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\BehaviorAnnotation;
use App\Models\Group;
use App\Models\GroupSubject;
use App\Models\Institution;
use App\Models\InstitutionTeacher;
use App\Models\Student;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BehaviorAnnotationTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Institution $institution;

    private Group $group;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->institution = Institution::create([
            'name' => 'Colegio Test', 'city' => 'Cali', 'department' => 'Valle', 'created_by' => $this->teacher->id,
        ]);
        InstitutionTeacher::create([
            'institution_id' => $this->institution->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'status' => 'active',
        ]);
        $academicYear = AcademicYear::create([
            'institution_id' => $this->institution->id, 'year' => 2026,
            'start_date' => '2026-01-20', 'end_date' => '2026-11-28',
        ]);
        $this->group = Group::create([
            'institution_id' => $this->institution->id, 'academic_year_id' => $academicYear->id,
            'name' => '10-1', 'grade_level' => '10',
        ]);
        $subject = Subject::create(['institution_id' => $this->institution->id, 'name' => 'Matemáticas']);
        GroupSubject::create([
            'group_id' => $this->group->id, 'subject_id' => $subject->id, 'user_id' => $this->teacher->id,
            'institution_id' => $this->institution->id, 'academic_year_id' => $academicYear->id,
        ]);

        $this->student = Student::create(['institution_id' => $this->institution->id, 'first_name' => 'Ana', 'last_name' => 'Gómez']);
        StudentGroup::create([
            'student_id' => $this->student->id, 'group_id' => $this->group->id, 'academic_year_id' => $academicYear->id,
            'enrollment_date' => '2026-01-20', 'status' => 'activo',
        ]);
    }

    private function payload(): array
    {
        return [
            'student_id' => $this->student->id,
            'group_id' => $this->group->id,
            'date' => '2026-02-05',
            'type' => 'negativa',
            'category' => 'convivencia',
            'title' => 'Llegó tarde repetidamente',
            'description' => 'El estudiante llegó tarde tres veces esta semana.',
            'requires_parent_contact' => true,
        ];
    }

    public function test_the_teacher_who_teaches_the_student_can_create_and_view_annotations(): void
    {
        $response = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/behavior', $this->payload());
        $response->assertCreated();

        $list = $this->actingAs($this->teacher, 'sanctum')->getJson("/api/behavior?groupId={$this->group->id}");
        $list->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_another_teacher_of_the_same_student_can_see_annotations_they_didnt_register(): void
    {
        BehaviorAnnotation::create([
            ...$this->payload(),
            'registered_by' => $this->teacher->id,
        ]);

        $otherTeacher = User::factory()->create();
        InstitutionTeacher::create([
            'institution_id' => $this->institution->id, 'user_id' => $otherTeacher->id, 'role' => 'teacher', 'status' => 'active',
        ]);
        $otherSubject = Subject::create(['institution_id' => $this->institution->id, 'name' => 'Español']);
        GroupSubject::create([
            'group_id' => $this->group->id, 'subject_id' => $otherSubject->id, 'user_id' => $otherTeacher->id,
            'institution_id' => $this->institution->id, 'academic_year_id' => $this->group->academic_year_id,
        ]);

        $response = $this->actingAs($otherTeacher, 'sanctum')->getJson("/api/behavior?groupId={$this->group->id}");

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_teacher_who_doesnt_teach_the_student_cannot_see_annotations(): void
    {
        BehaviorAnnotation::create([
            ...$this->payload(),
            'registered_by' => $this->teacher->id,
        ]);

        $outsider = User::factory()->create();
        InstitutionTeacher::create([
            'institution_id' => $this->institution->id, 'user_id' => $outsider->id, 'role' => 'teacher', 'status' => 'active',
        ]);

        $response = $this->actingAs($outsider, 'sanctum')->getJson("/api/behavior?groupId={$this->group->id}");

        $response->assertForbidden();
    }

    public function test_mark_contacted_sets_parent_contacted_flag(): void
    {
        $annotation = BehaviorAnnotation::create([
            ...$this->payload(),
            'registered_by' => $this->teacher->id,
        ]);

        $response = $this->actingAs($this->teacher, 'sanctum')->patchJson("/api/behavior/{$annotation->id}/mark-contacted");

        $response->assertOk()->assertJsonPath('data.parent_contacted', true);
        $this->assertDatabaseHas('behavior_annotations', ['id' => $annotation->id, 'parent_contacted' => true]);
    }

    public function test_only_the_author_or_an_admin_can_edit_or_delete_an_annotation(): void
    {
        $annotation = BehaviorAnnotation::create([...$this->payload(), 'registered_by' => $this->teacher->id]);

        $colleague = User::factory()->create();
        InstitutionTeacher::create([
            'institution_id' => $this->institution->id, 'user_id' => $colleague->id, 'role' => 'teacher', 'status' => 'active',
        ]);
        GroupSubject::create([
            'group_id' => $this->group->id,
            'subject_id' => Subject::create(['institution_id' => $this->institution->id, 'name' => 'Español'])->id,
            'user_id' => $colleague->id, 'institution_id' => $this->institution->id,
            'academic_year_id' => $this->group->academic_year_id,
        ]);

        // Un colega del estudiante la ve, pero no la puede cambiar ni borrar.
        $this->actingAs($colleague, 'sanctum')->putJson("/api/behavior/{$annotation->id}", ['title' => 'Otro'])->assertForbidden();
        $this->actingAs($colleague, 'sanctum')->deleteJson("/api/behavior/{$annotation->id}")->assertForbidden();

        $this->actingAs($this->teacher, 'sanctum')->putJson("/api/behavior/{$annotation->id}", [
            'title' => 'Corregido', 'date' => '2026-02-04',
        ])->assertOk()->assertJsonPath('data.title', 'Corregido');

        $admin = User::factory()->create();
        InstitutionTeacher::create([
            'institution_id' => $this->institution->id, 'user_id' => $admin->id, 'role' => 'admin', 'status' => 'active',
        ]);
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/behavior/{$annotation->id}")->assertOk();
        $this->assertSoftDeleted('behavior_annotations', ['id' => $annotation->id]);
    }

}
