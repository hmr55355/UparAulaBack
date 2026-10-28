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

class WorkGroupTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private GroupSubject $groupSubject;

    private Period $period;

    private GradeColumn $column;

    /** @var Student[] */
    private array $students = [];

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

        foreach (['Ana', 'Beto', 'Carla', 'Dario'] as $name) {
            $student = Student::create(['institution_id' => $institution->id, 'first_name' => $name, 'last_name' => 'Test']);
            StudentGroup::create([
                'student_id' => $student->id, 'group_id' => $group->id, 'academic_year_id' => $academicYear->id,
                'enrollment_date' => '2026-01-20', 'status' => 'activo',
            ]);
            $this->students[] = $student;
        }

        $section = GradeSection::create([
            'group_subject_id' => $this->groupSubject->id, 'period_id' => $this->period->id,
            'name' => 'Trabajos', 'weight' => 100, 'final_calculation' => 'weighted_avg',
        ]);
        $this->column = GradeColumn::create([
            'grade_section_id' => $section->id, 'group_subject_id' => $this->groupSubject->id, 'period_id' => $this->period->id,
            'column_type' => 'manual', 'name' => 'Exposición', 'short_name' => 'Exp', 'weight' => 100,
        ]);
    }

    private function sync(array $groups)
    {
        return $this->actingAs($this->teacher, 'sanctum')
            ->putJson("/api/grade-columns/{$this->column->id}/work-groups", ['groups' => $groups]);
    }

    private function scoreOf(Student $student): ?float
    {
        $score = Grade::where('student_id', $student->id)->where('grade_column_id', $this->column->id)->value('score');

        return $score === null ? null : (float) $score;
    }

    public function test_group_grade_is_copied_to_every_member_and_recalculates_the_final(): void
    {
        [$ana, $beto, $carla] = $this->students;

        $this->sync([
            ['name' => 'Grupo 1', 'student_ids' => [$ana->id, $beto->id], 'score' => 8.5],
            ['name' => 'Grupo 2', 'student_ids' => [$carla->id], 'score' => null],
        ])->assertOk()->assertJsonPath('grades_updated', 2)->assertJsonCount(2, 'data');

        $this->assertSame(8.5, $this->scoreOf($ana));
        $this->assertSame(8.5, $this->scoreOf($beto));
        $this->assertNull($this->scoreOf($carla));
        $this->assertEquals(8.5, (float) PeriodFinal::where('student_id', $ana->id)->value('period_final'));

        // La planilla trae los grupos de la actividad.
        $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/grades?groupSubjectId={$this->groupSubject->id}&periodId={$this->period->id}")
            ->assertOk()
            ->assertJsonPath("work_groups.{$this->column->id}.0.name", 'Grupo 1')
            ->assertJsonPath("work_groups.{$this->column->id}.0.student_ids", [$ana->id, $beto->id]);
    }

    public function test_an_unchanged_group_grade_keeps_a_members_individual_grade_and_only_reaches_new_members(): void
    {
        [$ana, $beto, $carla] = $this->students;
        $groupId = $this->sync([['name' => 'G1', 'student_ids' => [$ana->id, $beto->id], 'score' => 8.0]])->json('data.0.id');

        // El docente le cambia la nota a Beto en la planilla.
        Grade::where('student_id', $beto->id)->where('grade_column_id', $this->column->id)->update(['score' => 6.0]);

        $this->sync([['id' => $groupId, 'name' => 'G1', 'student_ids' => [$ana->id, $beto->id, $carla->id], 'score' => 8.0]])
            ->assertOk()->assertJsonPath('grades_updated', 1);
        $this->assertSame(6.0, $this->scoreOf($beto));
        $this->assertSame(8.0, $this->scoreOf($carla));

        // Cambiar la nota del grupo sí se la reescribe a todos.
        $this->sync([['id' => $groupId, 'name' => 'G1', 'student_ids' => [$ana->id, $beto->id, $carla->id], 'score' => 9.0]])
            ->assertOk()->assertJsonPath('grades_updated', 3);
        $this->assertSame(9.0, $this->scoreOf($beto));
    }

    public function test_a_student_can_move_between_groups_and_removed_groups_are_deleted(): void
    {
        [$ana, $beto] = $this->students;
        $ids = collect($this->sync([
            ['name' => 'G1', 'student_ids' => [$ana->id]],
            ['name' => 'G2', 'student_ids' => [$beto->id]],
        ])->json('data'))->pluck('id');

        $this->sync([['id' => $ids[1], 'name' => 'G2', 'student_ids' => [$beto->id, $ana->id]]])
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.student_ids', [$ana->id, $beto->id]);
        $this->assertDatabaseMissing('work_groups', ['id' => $ids[0]]);
    }

    public function test_group_grade_accepts_the_teachers_conventions(): void
    {
        [$ana] = $this->students;
        $convention = GradeConvention::create(['user_id' => $this->teacher->id, 'code' => 'Ok', 'label' => 'Cumplió', 'value' => 10]);

        $this->sync([['name' => 'G1', 'student_ids' => [$ana->id], 'convention_id' => $convention->id]])->assertOk();

        $this->assertDatabaseHas('grades', ['student_id' => $ana->id, 'convention_id' => $convention->id, 'score' => 10]);
    }

    public function test_invalid_payloads_are_rejected(): void
    {
        [$ana] = $this->students;
        $stranger = Student::create(['institution_id' => $this->groupSubject->institution_id, 'first_name' => 'X', 'last_name' => 'Y']);

        $this->sync([['name' => 'G1', 'student_ids' => [$ana->id]], ['name' => 'G2', 'student_ids' => [$ana->id]]])->assertStatus(422);
        $this->sync([['name' => 'G1', 'student_ids' => [$stranger->id]]])->assertStatus(422);
        $this->sync([['name' => 'G1', 'student_ids' => [$ana->id], 'score' => 11]])->assertStatus(422);

        $this->period->update(['is_closed' => true]);
        $this->sync([['name' => 'G1', 'student_ids' => [$ana->id]]])->assertStatus(422);
    }

    public function test_another_teacher_cannot_manage_the_groups(): void
    {
        $other = User::factory()->create();
        InstitutionTeacher::create([
            'institution_id' => $this->groupSubject->institution_id, 'user_id' => $other->id, 'role' => 'teacher', 'status' => 'active',
        ]);

        $this->actingAs($other, 'sanctum')
            ->putJson("/api/grade-columns/{$this->column->id}/work-groups", ['groups' => []])
            ->assertForbidden();
    }
}
