<?php

namespace Tests\Feature;

use App\Models\GradeColumn;
use App\Models\GradeSection;
use App\Models\Group;
use App\Models\GroupSubject;
use App\Models\Period;
use App\Models\SectionFinal;
use App\Models\Student;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PeriodManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Period $first;

    private Period $second;

    private GroupSubject $groupSubject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $institutionId = $this->actingAs($this->admin, 'sanctum')->postJson('/api/institutions', [
            'name' => 'Colegio', 'city' => 'Valledupar', 'department' => 'Cesar',
            'academic_year' => 2026, 'academic_year_start' => '2026-01-20', 'academic_year_end' => '2026-11-28',
        ])->json('data.id');

        $periods = Period::orderBy('number')->get();
        [$this->first, $this->second] = [$periods[0], $periods[1]];

        $group = Group::create([
            'institution_id' => $institutionId, 'academic_year_id' => $this->first->academic_year_id,
            'name' => '10-1', 'grade_level' => '10',
        ]);
        $this->groupSubject = GroupSubject::create([
            'group_id' => $group->id, 'subject_id' => Subject::create(['institution_id' => $institutionId, 'name' => 'Física'])->id,
            'user_id' => $this->admin->id, 'institution_id' => $institutionId, 'academic_year_id' => $this->first->academic_year_id,
        ]);
        $student = Student::create(['institution_id' => $institutionId, 'first_name' => 'Ana', 'last_name' => 'Díaz']);
        StudentGroup::create([
            'student_id' => $student->id, 'group_id' => $group->id, 'academic_year_id' => $this->first->academic_year_id,
            'enrollment_date' => '2026-01-20', 'status' => 'activo',
        ]);
    }

    public function test_a_period_can_be_renamed_and_moved_but_not_overlapping_another(): void
    {
        // Solo cambia el fin: se valida contra el inicio que ya tenía.
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/periods/{$this->first->id}", [
            'name' => 'Periodo I', 'end_date' => $this->first->end_date->copy()->subDays(3)->toDateString(),
        ])->assertOk()->assertJsonPath('data.name', 'Periodo I');

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/periods/{$this->first->id}", [
            'end_date' => $this->second->start_date->copy()->addDays(5)->toDateString(),
        ])->assertStatus(422)->assertJsonPath('errors.start_date.0', fn ($m) => str_contains($m, $this->second->name));

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/periods/{$this->first->id}", [
            'start_date' => $this->first->end_date->toDateString(), 'end_date' => $this->first->start_date->toDateString(),
        ])->assertStatus(422);
    }

    public function test_a_closed_period_rejects_grade_changes_and_adjustments(): void
    {
        $section = GradeSection::create([
            'group_subject_id' => $this->groupSubject->id, 'period_id' => $this->first->id,
            'name' => 'Evaluaciones', 'weight' => 100, 'final_calculation' => 'weighted_avg',
        ]);
        $column = GradeColumn::create([
            'grade_section_id' => $section->id, 'group_subject_id' => $this->groupSubject->id, 'period_id' => $this->first->id,
            'column_type' => 'manual', 'name' => 'Quiz', 'weight' => 100,
        ]);
        $studentId = Student::value('id');
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/grades', [
            'student_id' => $studentId, 'grade_column_id' => $column->id, 'score' => 7.0,
        ])->assertCreated();
        $sectionFinal = SectionFinal::firstOrFail();

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/periods/{$this->first->id}", ['is_closed' => true])->assertOk();

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/grades', [
            'student_id' => $studentId, 'grade_column_id' => $column->id, 'score' => 9.0,
        ])->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/section-finals/{$sectionFinal->id}/adjust", [
            'section_final' => 9.0, 'adjustment_reason' => 'x',
        ])->assertStatus(422);

        // Al reabrirlo se puede de nuevo.
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/periods/{$this->first->id}", ['is_closed' => false])->assertOk();
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/grades', [
            'student_id' => $studentId, 'grade_column_id' => $column->id, 'score' => 9.0,
        ])->assertCreated();
    }
}
