<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\GradeTemplate;
use App\Models\Group;
use App\Models\GroupSubject;
use App\Models\Institution;
use App\Models\InstitutionTeacher;
use App\Models\Period;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GradeTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Institution $institution;

    private GroupSubject $groupSubject;

    private Period $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->institution = Institution::create([
            'name' => 'Colegio Test', 'city' => 'Cali', 'department' => 'Valle', 'created_by' => $this->teacher->id,
        ]);
        $academicYear = AcademicYear::create([
            'institution_id' => $this->institution->id, 'year' => 2026,
            'start_date' => '2026-01-20', 'end_date' => '2026-11-28',
        ]);
        $this->period = Period::create([
            'academic_year_id' => $academicYear->id, 'number' => 1, 'name' => 'Primer Período',
            'start_date' => '2026-01-20', 'end_date' => '2026-03-20', 'is_active' => true,
        ]);
        $group = Group::create([
            'institution_id' => $this->institution->id, 'academic_year_id' => $academicYear->id,
            'name' => '10-1', 'grade_level' => '10',
        ]);
        $subject = Subject::create(['institution_id' => $this->institution->id, 'name' => 'Matemáticas']);
        $this->groupSubject = GroupSubject::create([
            'group_id' => $group->id, 'subject_id' => $subject->id, 'user_id' => $this->teacher->id,
            'institution_id' => $this->institution->id, 'academic_year_id' => $academicYear->id,
        ]);
        InstitutionTeacher::create([
            'institution_id' => $this->institution->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'status' => 'active',
        ]);
    }

    private function makeTemplate(): GradeTemplate
    {
        return GradeTemplate::create([
            'user_id' => $this->teacher->id,
            'institution_id' => $this->institution->id,
            'name' => 'Plantilla Demo',
            'is_shared' => true,
            'sections_config' => [
                'sections' => [
                    [
                        'name' => 'Aptitud', 'weight' => 100, 'final_calculation' => 'weighted_avg',
                        'columns' => [
                            ['name' => 'Comportamiento', 'short_name' => 'Com', 'column_type' => 'manual', 'weight' => 100],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_applying_a_template_creates_sections_and_columns(): void
    {
        $template = $this->makeTemplate();

        $response = $this->actingAs($this->teacher, 'sanctum')->postJson("/api/grade-templates/{$template->id}/apply", [
            'group_subject_id' => $this->groupSubject->id,
            'period_id' => $this->period->id,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('grade_sections', [
            'group_subject_id' => $this->groupSubject->id, 'period_id' => $this->period->id, 'name' => 'Aptitud',
        ]);
        $this->assertDatabaseCount('grade_columns', 1);
    }

    public function test_cannot_apply_a_template_to_an_already_configured_period(): void
    {
        $template = $this->makeTemplate();

        $this->actingAs($this->teacher, 'sanctum')->postJson("/api/grade-templates/{$template->id}/apply", [
            'group_subject_id' => $this->groupSubject->id,
            'period_id' => $this->period->id,
        ])->assertOk();

        $response = $this->actingAs($this->teacher, 'sanctum')->postJson("/api/grade-templates/{$template->id}/apply", [
            'group_subject_id' => $this->groupSubject->id,
            'period_id' => $this->period->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('grade_sections', 1);
    }
}
