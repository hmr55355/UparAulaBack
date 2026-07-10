<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassPlan;
use App\Models\Group;
use App\Models\GroupSubject;
use App\Models\Institution;
use App\Models\InstitutionTeacher;
use App\Models\Period;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassPlanTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private GroupSubject $groupSubject;

    private Period $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $institution = Institution::create([
            'name' => 'Colegio Test', 'city' => 'Cali', 'department' => 'Valle',
            'min_passing_grade' => 6.0, 'created_by' => $this->teacher->id,
        ]);
        $academicYear = AcademicYear::create([
            'institution_id' => $institution->id, 'year' => 2026,
            'start_date' => '2026-01-20', 'end_date' => '2026-11-28',
        ]);
        $this->period = Period::create([
            'academic_year_id' => $academicYear->id, 'number' => 1, 'name' => 'Primer Período',
            'start_date' => '2026-01-20', 'end_date' => '2026-03-20', 'is_active' => true,
        ]);
        $group = Group::create([
            'institution_id' => $institution->id, 'academic_year_id' => $academicYear->id,
            'name' => '10-1', 'grade_level' => '10',
        ]);
        $subject = Subject::create(['institution_id' => $institution->id, 'name' => 'Matemáticas']);
        $this->groupSubject = GroupSubject::create([
            'group_id' => $group->id, 'subject_id' => $subject->id, 'user_id' => $this->teacher->id,
            'institution_id' => $institution->id, 'academic_year_id' => $academicYear->id,
        ]);
        InstitutionTeacher::create([
            'institution_id' => $institution->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'status' => 'active',
        ]);
    }

    public function test_creating_a_class_plan_resolves_the_period_from_the_date(): void
    {
        $response = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/class-plans', [
            'group_subject_id' => $this->groupSubject->id,
            'date' => '2026-02-05',
            'topic' => 'Ángulos de referencia',
        ]);

        $response->assertCreated();
        $this->assertEquals($this->period->id, $response->json('data.period_id'));
        $this->assertEquals('planeada', $response->json('data.status'));
    }

    public function test_previous_finds_the_latest_executed_or_pending_plan_regardless_of_gap(): void
    {
        ClassPlan::create([
            'group_subject_id' => $this->groupSubject->id, 'period_id' => $this->period->id,
            'registered_by' => $this->teacher->id, 'date' => '2026-01-22', 'topic' => 'Vieja',
            'status' => 'ejecutada', 'pending_for_next_class' => 'Contenido antiguo, no debe salir',
        ]);
        // Simula un puente festivo: casi dos semanas después sigue siendo "la anterior".
        ClassPlan::create([
            'group_subject_id' => $this->groupSubject->id, 'period_id' => $this->period->id,
            'registered_by' => $this->teacher->id, 'date' => '2026-02-03', 'topic' => 'Ángulos de referencia',
            'status' => 'ejecutada', 'pending_for_next_class' => 'Repasar ejercicios de la página 82',
        ]);
        ClassPlan::create([
            'group_subject_id' => $this->groupSubject->id, 'period_id' => $this->period->id,
            'registered_by' => $this->teacher->id, 'date' => '2026-02-10', 'topic' => 'Cancelada por lluvia',
            'status' => 'cancelada',
        ]);

        $response = $this->actingAs($this->teacher, 'sanctum')->getJson(
            "/api/class-plans/previous?groupSubjectId={$this->groupSubject->id}"
        );

        $response->assertOk();
        $this->assertEquals('Ángulos de referencia', $response->json('data.topic'));
    }

    public function test_duplicate_as_base_copies_the_pending_as_the_new_topic(): void
    {
        $previous = ClassPlan::create([
            'group_subject_id' => $this->groupSubject->id, 'period_id' => $this->period->id,
            'registered_by' => $this->teacher->id, 'date' => '2026-02-03', 'topic' => 'Ángulos de referencia',
            'status' => 'ejecutada', 'pending_for_next_class' => 'Repasar ejercicios de la página 82',
        ]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/class-plans/{$previous->id}/duplicate-as-base");

        $response->assertCreated();
        $this->assertEquals('Repasar ejercicios de la página 82', $response->json('data.topic'));
        $this->assertEquals('planeada', $response->json('data.status'));
        $this->assertNull($response->json('data.objectives'));
    }

    public function test_a_teacher_who_doesnt_teach_the_course_cannot_create_or_view_plans(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider, 'sanctum')->postJson('/api/class-plans', [
            'group_subject_id' => $this->groupSubject->id,
            'date' => '2026-02-05',
            'topic' => 'Intruso',
        ])->assertForbidden();

        $this->actingAs($outsider, 'sanctum')->getJson(
            "/api/class-plans/previous?groupSubjectId={$this->groupSubject->id}"
        )->assertForbidden();
    }
}
