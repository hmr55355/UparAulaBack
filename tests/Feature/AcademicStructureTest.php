<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassSchedule;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\GroupSubject;
use App\Models\Institution;
use App\Models\InstitutionTeacher;
use App\Models\Shift;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicStructureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Institution $institution;

    private AcademicYear $year;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->institution = Institution::create([
            'name' => 'Colegio Test', 'city' => 'Cali', 'department' => 'Valle',
            'min_passing_grade' => 6.0, 'created_by' => $this->admin->id,
        ]);
        $this->year = AcademicYear::create([
            'institution_id' => $this->institution->id, 'year' => 2026,
            'start_date' => '2026-01-20', 'end_date' => '2026-11-28', 'is_active' => true,
        ]);
        $this->shift = $this->institution->shifts()->create(['name' => 'Mañana']);
        InstitutionTeacher::create([
            'institution_id' => $this->institution->id, 'user_id' => $this->admin->id, 'role' => 'admin', 'status' => 'active',
        ]);
    }

    private function gradeLevel(string $name, int $level): GradeLevel
    {
        return $this->institution->gradeLevels()->create(['name' => $name, 'level' => $level, 'sort_order' => $level]);
    }

    public function test_creating_a_group_with_a_grade_level_fills_the_legacy_text_and_default_shift(): void
    {
        $decimo = $this->gradeLevel('Décimo', 10);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/groups', [
            'institution_id' => $this->institution->id,
            'academic_year_id' => $this->year->id,
            'name' => '1001',
            'grade_level_id' => $decimo->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('groups', [
            'name' => '1001', 'grade_level_id' => $decimo->id, 'grade_level' => '10', 'shift_id' => $this->shift->id,
        ]);
    }

    public function test_assignment_only_allows_subjects_linked_to_the_group_grade(): void
    {
        $decimo = $this->gradeLevel('Décimo', 10);
        $once = $this->gradeLevel('Once', 11);
        $group = Group::create([
            'institution_id' => $this->institution->id, 'academic_year_id' => $this->year->id,
            'grade_level_id' => $decimo->id, 'shift_id' => $this->shift->id, 'name' => '1001', 'grade_level' => '10',
        ]);
        $trigonometria = Subject::create(['institution_id' => $this->institution->id, 'name' => 'Trigonometría']);
        $calculo = Subject::create(['institution_id' => $this->institution->id, 'name' => 'Cálculo']);

        // Vincular materias a grados desde la materia (una puede ir a varios grados).
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/subjects/{$trigonometria->id}", [
            'grade_level_ids' => [$decimo->id, $once->id],
        ])->assertOk();
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/subjects/{$calculo->id}", [
            'grade_level_ids' => [$once->id],
        ])->assertOk();

        $grid = $this->actingAs($this->admin, 'sanctum')->getJson("/api/institutions/{$this->institution->id}/assignment-grid");
        $grid->assertOk();
        $this->assertEquals(
            [['group_id' => $group->id, 'subject_id' => $trigonometria->id]],
            $grid->json('available_pairs')
        );

        $payload = ['group_id' => $group->id, 'user_id' => $this->admin->id, 'academic_year_id' => $this->year->id];
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/institutions/{$this->institution->id}/assign-course", $payload + ['subject_id' => $calculo->id])
            ->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/institutions/{$this->institution->id}/assign-course", $payload + ['subject_id' => $trigonometria->id])
            ->assertOk();
    }

    public function test_class_blocks_are_saved_in_order_and_overlaps_are_rejected(): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/shifts/{$this->shift->id}/class-blocks", [
            'blocks' => [
                ['type' => 'clase', 'label' => '1', 'start_time' => '06:15', 'end_time' => '07:10'],
                ['type' => 'clase', 'label' => '2', 'start_time' => '07:00', 'end_time' => '08:05'],
            ],
        ])->assertStatus(422);

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/shifts/{$this->shift->id}/class-blocks", [
            'blocks' => [
                ['type' => 'descanso', 'label' => 'Descanso', 'start_time' => '09:00', 'end_time' => '09:30'],
                ['type' => 'clase', 'label' => '1', 'start_time' => '06:15', 'end_time' => '07:10'],
            ],
        ])->assertOk()->assertJsonPath('data.0.label', '1')->assertJsonPath('data.1.type', 'descanso');
    }

    public function test_blocks_can_be_inferred_from_existing_class_schedules(): void
    {
        $group = Group::create([
            'institution_id' => $this->institution->id, 'academic_year_id' => $this->year->id,
            'shift_id' => $this->shift->id, 'name' => '1101', 'grade_level' => '11',
        ]);
        $subject = Subject::create(['institution_id' => $this->institution->id, 'name' => 'Cálculo']);
        $groupSubject = GroupSubject::create([
            'group_id' => $group->id, 'subject_id' => $subject->id, 'user_id' => $this->admin->id,
            'institution_id' => $this->institution->id, 'academic_year_id' => $this->year->id,
        ]);
        foreach ([['06:15', '08:05'], ['07:10', '08:05'], ['09:30', '10:25']] as [$start, $end]) {
            ClassSchedule::create([
                'group_subject_id' => $groupSubject->id, 'user_id' => $this->admin->id, 'day_of_week' => 1,
                'start_time' => $start, 'end_time' => $end, 'academic_year_id' => $this->year->id,
            ]);
        }

        $blocks = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/shifts/{$this->shift->id}/class-blocks/infer")
            ->assertOk()
            ->json('data');

        $this->assertEquals([
            ['type' => 'clase', 'label' => '1', 'start_time' => '06:15', 'end_time' => '07:10'],
            ['type' => 'clase', 'label' => '2', 'start_time' => '07:10', 'end_time' => '08:05'],
            ['type' => 'descanso', 'label' => 'Descanso', 'start_time' => '08:05', 'end_time' => '09:30'],
            ['type' => 'clase', 'label' => '3', 'start_time' => '09:30', 'end_time' => '10:25'],
        ], $blocks);
    }

    public function test_the_last_shift_of_an_institution_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/shifts/{$this->shift->id}")->assertStatus(422);
    }
}
