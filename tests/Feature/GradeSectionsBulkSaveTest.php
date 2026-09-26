<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Group;
use App\Models\GroupSubject;
use App\Models\Institution;
use App\Models\InstitutionTeacher;
use App\Models\Period;
use App\Models\Student;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GradeSectionsBulkSaveTest extends TestCase
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
            'name' => 'Colegio Test', 'city' => 'Cali', 'department' => 'Valle', 'created_by' => $this->teacher->id,
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

        $student = Student::create(['institution_id' => $institution->id, 'first_name' => 'Ana', 'last_name' => 'Gómez']);
        StudentGroup::create([
            'student_id' => $student->id, 'group_id' => $group->id, 'academic_year_id' => $academicYear->id,
            'enrollment_date' => '2026-01-20', 'status' => 'activo',
        ]);
    }

    public function test_bulk_save_rejects_section_weights_that_dont_sum_to_100(): void
    {
        $payload = [
            'group_subject_id' => $this->groupSubject->id,
            'period_id' => $this->period->id,
            'sections' => [
                [
                    'name' => 'Aptitud', 'weight' => 40, 'final_calculation' => 'weighted_avg',
                    'columns' => [['name' => 'Comportamiento', 'short_name' => 'Com', 'column_type' => 'manual', 'weight' => 100]],
                ],
                [
                    'name' => 'Evaluaciones', 'weight' => 40, 'final_calculation' => 'weighted_avg',
                    'columns' => [['name' => 'ev1', 'short_name' => 'ev1', 'column_type' => 'manual', 'weight' => 100]],
                ],
            ],
        ];

        $response = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/grade-sections/bulk-save', $payload);

        $response->assertUnprocessable()->assertJsonValidationErrors('sections');
        $this->assertDatabaseCount('grade_sections', 0);
    }

    public function test_bulk_save_rejects_column_weights_that_dont_sum_to_100_within_a_section(): void
    {
        $payload = [
            'group_subject_id' => $this->groupSubject->id,
            'period_id' => $this->period->id,
            'sections' => [
                [
                    'name' => 'Aptitud', 'weight' => 100, 'final_calculation' => 'weighted_avg',
                    'columns' => [
                        ['name' => 'Comportamiento', 'short_name' => 'Com', 'column_type' => 'manual', 'weight' => 50],
                        ['name' => 'Autoevaluación', 'short_name' => 'Auto', 'column_type' => 'manual', 'weight' => 30],
                    ],
                ],
            ],
        ];

        $response = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/grade-sections/bulk-save', $payload);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['sections.0.columns']);
    }

    public function test_bulk_save_creates_sections_and_columns_when_weights_are_valid(): void
    {
        $payload = [
            'group_subject_id' => $this->groupSubject->id,
            'period_id' => $this->period->id,
            'sections' => [
                [
                    'name' => 'Aptitud', 'weight' => 100, 'final_calculation' => 'weighted_avg',
                    'columns' => [
                        ['name' => 'Comportamiento', 'short_name' => 'Com', 'column_type' => 'manual', 'weight' => 100],
                    ],
                ],
            ],
        ];

        $response = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/grade-sections/bulk-save', $payload);

        $response->assertOk();
        $this->assertDatabaseCount('grade_sections', 1);
        $this->assertDatabaseHas('grade_sections', ['name' => 'Aptitud', 'weight' => 100]);
        $this->assertDatabaseCount('grade_columns', 1);
    }

    public function test_a_teacher_who_doesnt_own_the_course_cannot_bulk_save(): void
    {
        $outsider = User::factory()->create();

        $payload = [
            'group_subject_id' => $this->groupSubject->id,
            'period_id' => $this->period->id,
            'sections' => [
                [
                    'name' => 'Aptitud', 'weight' => 100,
                    'columns' => [['name' => 'Comportamiento', 'short_name' => 'Com', 'column_type' => 'manual', 'weight' => 100]],
                ],
            ],
        ];

        $response = $this->actingAs($outsider, 'sanctum')->postJson('/api/grade-sections/bulk-save', $payload);

        $response->assertForbidden();
    }

    public function test_bulk_save_keeps_column_fields_it_did_not_receive_and_saves_the_final_label(): void
    {
        $payload = fn (array $column, array $section = []) => [
            'group_subject_id' => $this->groupSubject->id,
            'period_id' => $this->period->id,
            'sections' => [[
                ...$section,
                'name' => 'Tareas', 'weight' => 100, 'final_calculation' => 'weighted_avg',
                'columns' => [['name' => 'Taller', 'column_type' => 'manual', 'weight' => 100, ...$column]],
            ]],
        ];

        $saved = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/grade-sections/bulk-save', $payload(
            ['max_score' => 5, 'description' => 'Ejercicios 1 a 10', 'date' => '2026-02-03'],
            ['section_final_label' => 'DefT'],
        ))->assertOk()->json('data.0');

        // Un guardado sin esos campos no los debe devolver a los valores por defecto.
        $this->actingAs($this->teacher, 'sanctum')->postJson('/api/grade-sections/bulk-save', $payload(
            ['id' => $saved['columns'][0]['id'], 'name' => 'Taller 1'],
            ['id' => $saved['id']],
        ))->assertOk();

        $this->assertDatabaseHas('grade_columns', [
            'id' => $saved['columns'][0]['id'], 'name' => 'Taller 1', 'max_score' => 5.0, 'description' => 'Ejercicios 1 a 10',
        ]);
        $this->assertDatabaseHas('grade_sections', ['id' => $saved['id'], 'section_final_label' => 'DefT']);
    }

}
