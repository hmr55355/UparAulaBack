<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\InstitutionTeacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerformanceScaleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $institutionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->institutionId = $this->actingAs($this->admin, 'sanctum')->postJson('/api/institutions', [
            'name' => 'Colegio de Prueba', 'city' => 'Valledupar', 'department' => 'Cesar',
            'academic_year' => 2026, 'academic_year_start' => '2026-01-20', 'academic_year_end' => '2026-11-28',
        ])->assertCreated()->json('data.id');
    }

    /** Escala 1.0–5.0 típica de un SIEE. */
    private function scale(array $overrides = []): array
    {
        return [
            'grading_scale' => '1_to_5',
            'levels' => $overrides ?: [
                ['name' => 'Bajo', 'national_level' => 'bajo', 'min_score' => 1.0, 'max_score' => 2.9, 'color' => '#C62828'],
                ['name' => 'Básico', 'national_level' => 'basico', 'min_score' => 3.0, 'max_score' => 3.9, 'color' => '#F9A825'],
                ['name' => 'Alto', 'national_level' => 'alto', 'min_score' => 4.0, 'max_score' => 4.5, 'color' => '#2E7D32'],
                ['name' => 'Superior', 'national_level' => 'superior', 'min_score' => 4.6, 'max_score' => 5.0, 'color' => '#1565C0'],
            ],
        ];
    }

    public function test_a_new_institution_starts_with_the_suggested_four_level_scale(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/institutions/current')
            ->assertOk()
            ->assertJsonCount(4, 'data.performance_levels')
            ->assertJsonPath('data.performance_levels.0.national_level', 'bajo')
            ->assertJsonPath('data.performance_levels.1.min_score', '6.0')
            ->assertJsonPath('data.performance_levels.3.max_score', '10.0');
    }

    public function test_saving_a_valid_scale_updates_the_grading_scale_and_the_passing_grade(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/institutions/{$this->institutionId}/performance-levels", $this->scale())
            ->assertOk()
            ->assertJsonPath('min_passing_grade', 3);

        $this->assertDatabaseHas('institutions', ['id' => $this->institutionId, 'grading_scale' => '1_to_5', 'min_passing_grade' => 3.0]);
        $this->assertDatabaseCount('performance_levels', 4);
    }

    public function test_gaps_and_missing_national_levels_are_rejected(): void
    {
        $withGap = $this->scale()['levels'];
        $withGap[2]['min_score'] = 4.1;
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/institutions/{$this->institutionId}/performance-levels", $this->scale($withGap))
            ->assertStatus(422);

        $withoutSuperior = $this->scale()['levels'];
        $withoutSuperior[3]['national_level'] = 'alto';
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/institutions/{$this->institutionId}/performance-levels", $this->scale($withoutSuperior))
            ->assertStatus(422)
            ->assertJsonPath('errors.levels.0', fn ($message) => str_contains($message, 'Desempeño Superior'));

        $this->assertDatabaseHas('institutions', ['id' => $this->institutionId, 'grading_scale' => '1_to_10']);
    }

    public function test_only_an_admin_can_change_the_scale(): void
    {
        $teacher = User::factory()->create();
        InstitutionTeacher::create([
            'institution_id' => $this->institutionId, 'user_id' => $teacher->id, 'role' => 'teacher', 'status' => 'active',
        ]);

        $this->actingAs($teacher, 'sanctum')->getJson("/api/institutions/{$this->institutionId}/performance-levels")->assertOk();
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/institutions/{$this->institutionId}/performance-levels", $this->scale())
            ->assertForbidden();
    }
}
