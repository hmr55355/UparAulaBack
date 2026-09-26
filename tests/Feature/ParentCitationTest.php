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

class ParentCitationTest extends TestCase
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

    public function test_can_add_a_parent_and_then_cite_them(): void
    {
        $parentResponse = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/parents', [
            'student_id' => $this->student->id,
            'first_name' => 'María',
            'last_name' => 'Pérez',
            'relationship' => 'madre',
            'phone' => '3001234567',
            'is_primary' => true,
        ]);
        $parentResponse->assertCreated();
        $parentId = $parentResponse->json('data.id');

        $citationResponse = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/citations', [
            'student_id' => $this->student->id,
            'parent_id' => $parentId,
            'group_id' => $this->group->id,
            'citation_type' => 'comportamiento',
            'reason' => 'Reiteradas llegadas tarde.',
            'scheduled_date' => '2026-02-10 08:00:00',
            'location' => 'Coordinación',
            'notification_method' => 'whatsapp',
        ]);

        $citationResponse->assertCreated()->assertJsonPath('data.status', 'pendiente');
        $this->assertDatabaseHas('parent_citations', ['student_id' => $this->student->id, 'parent_id' => $parentId]);
    }

    public function test_citation_can_be_linked_to_a_behavior_annotation(): void
    {
        $annotation = BehaviorAnnotation::create([
            'student_id' => $this->student->id, 'group_id' => $this->group->id, 'registered_by' => $this->teacher->id,
            'date' => '2026-02-05', 'type' => 'negativa', 'category' => 'convivencia',
            'title' => 'Falta de respeto', 'description' => 'Detalle.', 'requires_parent_contact' => true,
        ]);

        $response = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/citations', [
            'student_id' => $this->student->id,
            'group_id' => $this->group->id,
            'behavior_annotation_id' => $annotation->id,
            'citation_type' => 'comportamiento',
            'reason' => 'Seguimiento a la anotación.',
        ]);

        $response->assertCreated()->assertJsonPath('data.behavior_annotation_id', $annotation->id);
    }

    public function test_marking_as_realizado_requires_an_outcome(): void
    {
        $citation = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/citations', [
            'student_id' => $this->student->id,
            'group_id' => $this->group->id,
            'citation_type' => 'general',
            'reason' => 'Motivo.',
        ])->json('data');

        $missingOutcome = $this->actingAs($this->teacher, 'sanctum')->patchJson("/api/citations/{$citation['id']}/status", [
            'status' => 'realizado',
        ]);
        $missingOutcome->assertUnprocessable();

        $withOutcome = $this->actingAs($this->teacher, 'sanctum')->patchJson("/api/citations/{$citation['id']}/status", [
            'status' => 'realizado',
            'outcome' => 'Se acordó mejorar la puntualidad.',
            'commitments' => 'El estudiante llegará 10 minutos antes.',
        ]);
        $withOutcome->assertOk()->assertJsonPath('data.status', 'realizado');
    }

    public function test_a_teacher_who_doesnt_teach_the_student_cannot_create_a_citation(): void
    {
        $outsider = User::factory()->create();
        InstitutionTeacher::create([
            'institution_id' => $this->institution->id, 'user_id' => $outsider->id, 'role' => 'teacher', 'status' => 'active',
        ]);

        $response = $this->actingAs($outsider, 'sanctum')->postJson('/api/citations', [
            'student_id' => $this->student->id,
            'group_id' => $this->group->id,
            'citation_type' => 'general',
            'reason' => 'Motivo.',
        ]);

        $response->assertForbidden();
    }

    public function test_notifying_a_follow_up_citation_marks_its_annotation_as_contacted(): void
    {
        $annotation = BehaviorAnnotation::create([
            'student_id' => $this->student->id, 'group_id' => $this->group->id, 'registered_by' => $this->teacher->id,
            'date' => '2026-02-05', 'type' => 'negativa', 'category' => 'convivencia',
            'title' => 'Falta', 'description' => 'Detalle.', 'requires_parent_contact' => true,
        ]);
        $citationId = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/citations', [
            'student_id' => $this->student->id, 'group_id' => $this->group->id,
            'behavior_annotation_id' => $annotation->id, 'citation_type' => 'comportamiento', 'reason' => 'Seguimiento.',
        ])->json('data.id');

        $this->actingAs($this->teacher, 'sanctum')->getJson("/api/behavior?groupId={$this->group->id}")
            ->assertJsonPath('data.0.citations_count', 1)
            ->assertJsonPath('data.0.parent_contacted', false);

        $this->actingAs($this->teacher, 'sanctum')->patchJson("/api/citations/{$citationId}/status", ['status' => 'notificado'])
            ->assertOk();

        $this->assertDatabaseHas('behavior_annotations', ['id' => $annotation->id, 'parent_contacted' => true]);
    }

    public function test_the_author_can_reschedule_a_citation_and_a_colleague_cannot(): void
    {
        $citationId = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/citations', [
            'student_id' => $this->student->id, 'group_id' => $this->group->id,
            'citation_type' => 'academica', 'reason' => 'Bajo rendimiento.', 'scheduled_date' => '2026-02-10T10:00',
        ])->json('data.id');

        $this->actingAs($this->teacher, 'sanctum')->putJson("/api/citations/{$citationId}", [
            'scheduled_date' => '2026-02-12T07:30', 'location' => 'Coordinación',
        ])->assertOk()->assertJsonPath('data.location', 'Coordinación');
        $this->assertDatabaseHas('parent_citations', ['id' => $citationId, 'scheduled_date' => '2026-02-12 07:30:00']);

        $colleague = User::factory()->create();
        InstitutionTeacher::create([
            'institution_id' => $this->institution->id, 'user_id' => $colleague->id, 'role' => 'teacher', 'status' => 'active',
        ]);
        GroupSubject::create([
            'group_id' => $this->group->id,
            'subject_id' => Subject::create(['institution_id' => $this->institution->id, 'name' => 'Inglés'])->id,
            'user_id' => $colleague->id, 'institution_id' => $this->institution->id,
            'academic_year_id' => $this->group->academic_year_id,
        ]);
        $this->actingAs($colleague, 'sanctum')->deleteJson("/api/citations/{$citationId}")->assertForbidden();
        $this->actingAs($this->teacher, 'sanctum')->deleteJson("/api/citations/{$citationId}")->assertOk();
    }

}
