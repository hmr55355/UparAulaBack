<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\GradeColumn;
use App\Models\GradeConvention;
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

class GradeConventionTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private GroupSubject $groupSubject;

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
        $period = Period::create([
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
            'group_subject_id' => $this->groupSubject->id, 'period_id' => $period->id,
            'name' => 'Evaluaciones', 'weight' => 100, 'final_calculation' => 'weighted_avg',
        ]);
        $this->ev1 = GradeColumn::create([
            'grade_section_id' => $section->id, 'group_subject_id' => $this->groupSubject->id, 'period_id' => $period->id,
            'column_type' => 'manual', 'name' => 'ev1', 'short_name' => 'ev1', 'weight' => 50,
        ]);
        $this->ev2 = GradeColumn::create([
            'grade_section_id' => $section->id, 'group_subject_id' => $this->groupSubject->id, 'period_id' => $period->id,
            'column_type' => 'manual', 'name' => 'ev2', 'short_name' => 'ev2', 'weight' => 50,
        ]);
    }

    private function periodFinal(): ?float
    {
        $value = PeriodFinal::where('student_id', $this->student->id)
            ->where('group_subject_id', $this->groupSubject->id)
            ->value('period_final');

        return $value === null ? null : (float) $value;
    }

    public function test_teacher_manages_own_conventions_and_suggested_ones_are_not_duplicated(): void
    {
        $this->actingAs($this->teacher, 'sanctum')
            ->postJson('/api/grade-conventions', ['code' => 'NP', 'label' => 'No presentó', 'value' => 1.0])
            ->assertCreated();

        $this->actingAs($this->teacher, 'sanctum')
            ->postJson('/api/grade-conventions', ['code' => 'NP', 'label' => 'Otra'])
            ->assertStatus(422);

        $response = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/grade-conventions/suggested')->assertCreated();

        $codes = collect($response->json('data'))->pluck('code');
        $this->assertSame(1, $codes->filter(fn ($c) => $c === 'NP')->count());
        $this->assertContains('✓', $codes->all());

        $other = User::factory()->create();
        $this->actingAs($other, 'sanctum')->getJson('/api/grade-conventions')->assertOk()->assertJsonCount(0, 'data');
        $convention = GradeConvention::where('code', 'NP')->first();
        $this->actingAs($other, 'sanctum')->putJson("/api/grade-conventions/{$convention->id}", ['label' => 'x'])->assertForbidden();
    }

    public function test_convention_with_value_counts_and_blank_convention_is_left_out_of_the_average(): void
    {
        $np = GradeConvention::create(['user_id' => $this->teacher->id, 'code' => 'NP', 'label' => 'No presentó', 'value' => 2.0]);
        $ok = GradeConvention::create(['user_id' => $this->teacher->id, 'code' => '✓', 'label' => 'Entregado', 'value' => null]);

        $this->actingAs($this->teacher, 'sanctum')->postJson('/api/grades', [
            'student_id' => $this->student->id, 'grade_column_id' => $this->ev1->id, 'score' => 8.0,
        ])->assertCreated();

        $this->actingAs($this->teacher, 'sanctum')->postJson('/api/grades', [
            'student_id' => $this->student->id, 'grade_column_id' => $this->ev2->id, 'convention_id' => $np->id,
        ])->assertCreated()->assertJsonPath('data.convention_id', $np->id);
        $this->assertEquals(5.0, $this->periodFinal());

        // Sin nota: solo cuenta ev1.
        $this->actingAs($this->teacher, 'sanctum')->postJson('/api/grades', [
            'student_id' => $this->student->id, 'grade_column_id' => $this->ev2->id, 'convention_id' => $ok->id,
        ])->assertCreated();
        $this->assertEquals(8.0, $this->periodFinal());

        // Una nota numérica limpia la convención.
        $this->actingAs($this->teacher, 'sanctum')->postJson('/api/grades', [
            'student_id' => $this->student->id, 'grade_column_id' => $this->ev2->id, 'score' => 6.0,
        ])->assertCreated()->assertJsonPath('data.convention_id', null);
        $this->assertEquals(7.0, $this->periodFinal());
    }

    public function test_changing_a_convention_value_updates_existing_grades_and_finals(): void
    {
        $np = GradeConvention::create(['user_id' => $this->teacher->id, 'code' => 'NP', 'label' => 'No presentó', 'value' => 2.0]);

        $this->actingAs($this->teacher, 'sanctum')->postJson('/api/grades/bulk', ['grades' => [
            ['student_id' => $this->student->id, 'grade_column_id' => $this->ev1->id, 'score' => 8.0],
            ['student_id' => $this->student->id, 'grade_column_id' => $this->ev2->id, 'convention_id' => $np->id],
        ]])->assertCreated();
        $this->assertEquals(5.0, $this->periodFinal());

        $this->actingAs($this->teacher, 'sanctum')
            ->putJson("/api/grade-conventions/{$np->id}", ['value' => 4.0])
            ->assertOk();

        $this->assertEquals(4.0, (float) Grade::where('grade_column_id', $this->ev2->id)->value('score'));
        $this->assertEquals(6.0, $this->periodFinal());

        // En uso: no se puede borrar.
        $this->actingAs($this->teacher, 'sanctum')->deleteJson("/api/grade-conventions/{$np->id}")->assertStatus(422);
    }

    public function test_sheet_lists_course_teacher_conventions_and_rejects_foreign_ones(): void
    {
        GradeConvention::create(['user_id' => $this->teacher->id, 'code' => 'NA', 'label' => 'No asistió', 'value' => 1.0]);
        $foreign = GradeConvention::create(['user_id' => User::factory()->create()->id, 'code' => 'X', 'label' => 'Ajena']);

        $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/grades?groupSubjectId={$this->groupSubject->id}&periodId={$this->ev1->period_id}")
            ->assertOk()
            ->assertJsonCount(1, 'conventions')
            ->assertJsonPath('conventions.0.code', 'NA');

        $this->actingAs($this->teacher, 'sanctum')->postJson('/api/grades', [
            'student_id' => $this->student->id, 'grade_column_id' => $this->ev1->id, 'convention_id' => $foreign->id,
        ])->assertStatus(422);
    }
}
