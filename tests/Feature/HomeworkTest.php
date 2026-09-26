<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\GradeSection;
use App\Models\Group;
use App\Models\GroupSubject;
use App\Models\Institution;
use App\Models\InstitutionTeacher;
use App\Models\Period;
use App\Models\PeriodFinal;
use App\Models\Student;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeworkTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private GroupSubject $groupSubject;

    private Period $period;

    private GradeSection $section;

    private Student $student;

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

        $this->student = Student::create(['institution_id' => $institution->id, 'first_name' => 'Ana', 'last_name' => 'Gómez']);
        StudentGroup::create([
            'student_id' => $this->student->id, 'group_id' => $group->id, 'academic_year_id' => $academicYear->id,
            'enrollment_date' => '2026-01-20', 'status' => 'activo',
        ]);

        $this->section = GradeSection::create([
            'group_subject_id' => $this->groupSubject->id, 'period_id' => $this->period->id,
            'name' => 'Tareas', 'weight' => 100, 'final_calculation' => 'weighted_avg',
        ]);
    }

    public function test_creating_a_graded_homework_creates_a_grade_column_and_delivering_a_score_recalculates_definitivas(): void
    {
        $response = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/homeworks', [
            'group_subject_id' => $this->groupSubject->id,
            'period_id' => $this->period->id,
            'title' => 'Taller de fracciones',
            'assigned_date' => '2026-02-01',
            'due_date' => '2026-02-08',
            'max_score' => 10.0,
            'is_graded' => true,
            'grade_section_id' => $this->section->id,
            'weight' => 100,
        ]);

        $response->assertCreated();
        $homeworkId = $response->json('data.id');
        $gradeColumnId = $response->json('data.grade_column_id');
        $this->assertNotNull($gradeColumnId);
        $this->assertDatabaseHas('grade_columns', ['id' => $gradeColumnId, 'column_type' => 'manual', 'name' => 'Taller de fracciones']);

        $this->actingAs($this->teacher, 'sanctum')->postJson("/api/homeworks/{$homeworkId}/deliveries/bulk", [
            'deliveries' => [
                ['student_id' => $this->student->id, 'status' => 'entregado', 'score' => 8.0],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('homework_deliveries', [
            'homework_id' => $homeworkId, 'student_id' => $this->student->id, 'status' => 'entregado', 'score' => 8.0,
        ]);
        $this->assertDatabaseHas('grades', ['grade_column_id' => $gradeColumnId, 'student_id' => $this->student->id, 'score' => 8.0]);

        $periodFinal = PeriodFinal::where('student_id', $this->student->id)
            ->where('group_subject_id', $this->groupSubject->id)
            ->first();
        $this->assertNotNull($periodFinal);
        $this->assertEquals(8.0, (float) $periodFinal->period_final);
    }

    public function test_bulk_deliveries_upserts_instead_of_duplicating(): void
    {
        $homeworkId = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/homeworks', [
            'group_subject_id' => $this->groupSubject->id,
            'period_id' => $this->period->id,
            'title' => 'Lectura capítulo 3',
            'assigned_date' => '2026-02-01',
            'due_date' => '2026-02-05',
        ])->json('data.id');

        $this->actingAs($this->teacher, 'sanctum')->postJson("/api/homeworks/{$homeworkId}/deliveries/bulk", [
            'deliveries' => [['student_id' => $this->student->id, 'status' => 'no_entregado']],
        ])->assertCreated();

        $this->actingAs($this->teacher, 'sanctum')->postJson("/api/homeworks/{$homeworkId}/deliveries/bulk", [
            'deliveries' => [['student_id' => $this->student->id, 'status' => 'entregado_tarde']],
        ])->assertCreated();

        $this->assertDatabaseCount('homework_deliveries', 1);
        $this->assertDatabaseHas('homework_deliveries', [
            'homework_id' => $homeworkId, 'student_id' => $this->student->id, 'status' => 'entregado_tarde',
        ]);
    }

    public function test_a_teacher_who_doesnt_teach_the_course_cannot_create_or_view_homeworks(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider, 'sanctum')->postJson('/api/homeworks', [
            'group_subject_id' => $this->groupSubject->id,
            'period_id' => $this->period->id,
            'title' => 'Taller intruso',
            'assigned_date' => '2026-02-01',
            'due_date' => '2026-02-08',
        ])->assertForbidden();

        $this->actingAs($outsider, 'sanctum')->getJson(
            "/api/homeworks?groupSubjectId={$this->groupSubject->id}&periodId={$this->period->id}"
        )->assertForbidden();
    }

    public function test_editing_a_graded_homework_syncs_its_column_and_deleting_it_recalculates_finals(): void
    {
        $homework = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/homeworks', [
            'group_subject_id' => $this->groupSubject->id, 'period_id' => $this->period->id,
            'title' => 'Taller', 'assigned_date' => '2026-02-01', 'due_date' => '2026-02-08',
            'max_score' => 10.0, 'is_graded' => true, 'grade_section_id' => $this->section->id, 'weight' => 100,
        ])->assertCreated()->json('data');

        $this->actingAs($this->teacher, 'sanctum')->putJson("/api/homeworks/{$homework['id']}", [
            'title' => 'Taller corregido', 'max_score' => 5.0,
        ])->assertOk();
        $this->assertDatabaseHas('grade_columns', ['id' => $homework['grade_column_id'], 'name' => 'Taller corregido', 'max_score' => 5.0]);

        $this->actingAs($this->teacher, 'sanctum')->postJson("/api/homeworks/{$homework['id']}/deliveries/bulk", [
            'deliveries' => [['student_id' => $this->student->id, 'status' => 'entregado', 'score' => 4.0]],
        ])->assertCreated();
        $finalOf = fn () => PeriodFinal::where('student_id', $this->student->id)
            ->where('group_subject_id', $this->groupSubject->id)->value('period_final');
        $this->assertEquals(4.0, (float) $finalOf());

        // Con notas: primero pide confirmación; al confirmar se borra y se recalcula.
        $this->actingAs($this->teacher, 'sanctum')->deleteJson("/api/homeworks/{$homework['id']}")->assertStatus(409);
        $this->actingAs($this->teacher, 'sanctum')->deleteJson("/api/homeworks/{$homework['id']}?confirm=1")->assertOk();

        $this->assertDatabaseMissing('grade_columns', ['id' => $homework['grade_column_id']]);
        $this->assertNull($finalOf());
    }


    public function test_automatic_weight_splits_the_section_equally_among_its_columns(): void
    {
        $existing = \App\Models\GradeColumn::create([
            'grade_section_id' => $this->section->id, 'group_subject_id' => $this->groupSubject->id,
            'period_id' => $this->period->id, 'column_type' => 'manual', 'name' => 'Taller 1', 'weight' => 100, 'sort_order' => 0,
        ]);

        $columnId = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/homeworks', [
            'group_subject_id' => $this->groupSubject->id, 'period_id' => $this->period->id,
            'title' => 'Taller 2', 'assigned_date' => '2026-02-01', 'due_date' => '2026-02-08',
            'is_graded' => true, 'grade_section_id' => $this->section->id, 'weight_mode' => 'automatic',
            'notes' => 'Revisar en clase',
        ])->assertCreated()->assertJsonPath('data.notes', 'Revisar en clase')->json('data.grade_column_id');

        $this->assertDatabaseHas('grade_columns', ['id' => $existing->id, 'weight' => 50]);
        $this->assertDatabaseHas('grade_columns', ['id' => $columnId, 'weight' => 50, 'max_score' => 10]);
    }

}
