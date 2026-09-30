<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\GradeColumn;
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

class GradesApiTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private GroupSubject $groupSubject;

    private Period $period;

    private Student $student;

    private GradeColumn $ev1;

    private GradeColumn $ev2;

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

        $section = GradeSection::create([
            'group_subject_id' => $this->groupSubject->id, 'period_id' => $this->period->id,
            'name' => 'Evaluaciones', 'weight' => 100, 'final_calculation' => 'weighted_avg',
        ]);
        $this->ev1 = GradeColumn::create([
            'grade_section_id' => $section->id, 'group_subject_id' => $this->groupSubject->id, 'period_id' => $this->period->id,
            'column_type' => 'manual', 'name' => 'ev1', 'short_name' => 'ev1', 'weight' => 50,
        ]);
        $this->ev2 = GradeColumn::create([
            'grade_section_id' => $section->id, 'group_subject_id' => $this->groupSubject->id, 'period_id' => $this->period->id,
            'column_type' => 'manual', 'name' => 'ev2', 'short_name' => 'ev2', 'weight' => 50,
        ]);
    }

    public function test_saving_a_grade_via_the_api_automatically_recalculates_definitivas(): void
    {
        $this->actingAs($this->teacher, 'sanctum')->postJson('/api/grades', [
            'student_id' => $this->student->id,
            'grade_column_id' => $this->ev1->id,
            'score' => 8.0,
        ])->assertCreated();

        $this->actingAs($this->teacher, 'sanctum')->postJson('/api/grades', [
            'student_id' => $this->student->id,
            'grade_column_id' => $this->ev2->id,
            'score' => 6.0,
        ])->assertCreated();

        // Sin llamar a ningún endpoint de "calculate": el Observer ya debió recalcular.
        $periodFinal = PeriodFinal::where('student_id', $this->student->id)
            ->where('group_subject_id', $this->groupSubject->id)
            ->first();

        $this->assertNotNull($periodFinal);
        $this->assertEquals(7.0, (float) $periodFinal->period_final);
    }

    public function test_bulk_grades_endpoint_saves_multiple_scores_at_once(): void
    {
        $response = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/grades/bulk', [
            'grades' => [
                ['student_id' => $this->student->id, 'grade_column_id' => $this->ev1->id, 'score' => 9.0],
                ['student_id' => $this->student->id, 'grade_column_id' => $this->ev2->id, 'score' => 7.0],
            ],
        ]);

        $response->assertCreated()->assertJsonPath('count', 2);
        $this->assertDatabaseHas('grades', ['grade_column_id' => $this->ev1->id, 'score' => 9.0]);
    }

    public function test_cannot_edit_grades_of_a_closed_period(): void
    {
        $this->period->update(['is_closed' => true]);

        $response = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/grades', [
            'student_id' => $this->student->id,
            'grade_column_id' => $this->ev1->id,
            'score' => 8.0,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('grades', 0);
    }

    public function test_score_above_max_is_rejected(): void
    {
        $response = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/grades', [
            'student_id' => $this->student->id,
            'grade_column_id' => $this->ev1->id,
            'score' => 15.0,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('score');
    }

    public function test_a_teacher_who_doesnt_own_the_course_cannot_grade_it(): void
    {
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider, 'sanctum')->postJson('/api/grades', [
            'student_id' => $this->student->id,
            'grade_column_id' => $this->ev1->id,
            'score' => 8.0,
        ]);

        $response->assertForbidden();
    }

    public function test_an_activity_can_be_added_from_the_sheet_with_automatic_weight_and_renamed(): void
    {
        $response = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/grade-columns', [
            'grade_section_id' => $this->ev1->grade_section_id,
            'column_type' => 'manual',
            'name' => 'Quiz 1',
            'short_name' => 'Q1',
            'weight_mode' => 'automatic',
        ])->assertCreated()->assertJsonPath('data.max_score', '10.0');

        $columnId = $response->json('data.id');
        $this->assertEquals([33.3, 33.3, 33.4], \App\Models\GradeColumn::where('grade_section_id', $this->ev1->grade_section_id)
            ->orderBy('sort_order')->pluck('weight')->map(fn ($w) => (float) $w)->all());

        $this->actingAs($this->teacher, 'sanctum')->putJson("/api/grade-columns/{$columnId}", [
            'name' => 'Quiz de fracciones', 'short_name' => 'QF',
        ])->assertOk()->assertJsonPath('data.name', 'Quiz de fracciones');

        // Manual: sin peso se rechaza.
        $this->actingAs($this->teacher, 'sanctum')->postJson('/api/grade-columns', [
            'grade_section_id' => $this->ev1->grade_section_id, 'column_type' => 'manual', 'name' => 'Otra',
        ])->assertStatus(422);
    }


    public function test_a_manually_adjusted_final_survives_later_grades_and_feeds_the_period_final(): void
    {
        $this->actingAs($this->teacher, 'sanctum')->postJson('/api/grades', [
            'student_id' => $this->student->id, 'grade_column_id' => $this->ev1->id, 'score' => 5.0,
        ])->assertCreated();
        $sectionFinal = \App\Models\SectionFinal::where('student_id', $this->student->id)->firstOrFail();

        $this->actingAs($this->teacher, 'sanctum')->putJson("/api/section-finals/{$sectionFinal->id}/adjust", [
            'section_final' => 6.5, 'adjustment_reason' => 'Plan de mejoramiento',
        ])->assertOk();
        $periodFinal = fn () => (float) PeriodFinal::where('student_id', $this->student->id)->value('period_final');
        $this->assertEquals(6.5, $periodFinal());

        // Una nota nueva recalcula, pero no pisa el ajuste.
        $this->actingAs($this->teacher, 'sanctum')->postJson('/api/grades', [
            'student_id' => $this->student->id, 'grade_column_id' => $this->ev2->id, 'score' => 9.0,
        ])->assertCreated();
        $this->assertEquals(6.5, (float) $sectionFinal->fresh()->section_final);
        $this->assertEquals(6.5, $periodFinal());

        // Quitar el ajuste vuelve al calculado: (5.0 + 9.0) / 2.
        $this->actingAs($this->teacher, 'sanctum')->deleteJson("/api/section-finals/{$sectionFinal->id}/adjust")->assertOk();
        $this->assertFalse((bool) $sectionFinal->fresh()->manually_adjusted);
        $this->assertEquals(7.0, (float) $sectionFinal->fresh()->section_final);
        $this->assertEquals(7.0, $periodFinal());
    }

}
