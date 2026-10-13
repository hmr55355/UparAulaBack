<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Group;
use App\Models\InstitutionTeacher;
use App\Models\ResourceLoan;
use App\Models\Student;
use App\Models\StudentGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResourceLoanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private User $colleague;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $institutionId = $this->actingAs($this->admin, 'sanctum')->postJson('/api/institutions', [
            'name' => 'Colegio', 'city' => 'Valledupar', 'department' => 'Cesar',
            'academic_year' => 2026, 'academic_year_start' => '2026-01-20', 'academic_year_end' => '2026-11-28',
        ])->json('data.id');
        [$this->teacher, $this->colleague] = [User::factory()->create(), User::factory()->create()];
        foreach ([$this->teacher, $this->colleague] as $user) {
            InstitutionTeacher::create(['institution_id' => $institutionId, 'user_id' => $user->id, 'role' => 'teacher', 'status' => 'active']);
        }
        $group = Group::create([
            'institution_id' => $institutionId, 'academic_year_id' => AcademicYear::value('id'), 'name' => '1003', 'grade_level' => '10',
        ]);
        $this->student = Student::create(['institution_id' => $institutionId, 'first_name' => 'Ana', 'last_name' => 'Díaz']);
        StudentGroup::create([
            'student_id' => $this->student->id, 'group_id' => $group->id, 'academic_year_id' => $group->academic_year_id,
            'enrollment_date' => '2026-01-20', 'status' => 'activo',
        ]);
    }

    private function lend(User $as, array $data = []): int
    {
        return $this->actingAs($as, 'sanctum')->postJson('/api/resource-loans', [
            'student_id' => $this->student->id, 'category' => 'tecnologia', 'item_name' => 'micro:bit',
            'loan_date' => now()->subDays(10)->toDateString(), 'due_date' => now()->addDays(5)->toDateString(),
            ...$data,
        ])->assertCreated()->json('data.id');
    }

    public function test_lending_to_a_student_and_to_a_teacher_and_overdue_is_computed(): void
    {
        $toStudent = $this->lend($this->teacher, ['due_date' => now()->subDays(3)->toDateString(), 'quantity' => 2]);
        $toTeacher = $this->lend($this->teacher, [
            'student_id' => null, 'borrower_user_id' => $this->colleague->id, 'category' => 'portatil', 'item_name' => 'Portátil 07',
        ]);

        $response = $this->actingAs($this->teacher, 'sanctum')->getJson('/api/resource-loans')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('summary.active', 2)
            ->assertJsonPath('summary.overdue', 1);
        $overdue = collect($response->json('data'))->firstWhere('id', $toStudent);
        $this->assertTrue($overdue['is_overdue']);
        $this->assertSame(3, $overdue['days_overdue']);
        $this->assertSame('1003', $overdue['group']['name']);

        $this->actingAs($this->teacher, 'sanctum')->getJson('/api/resource-loans?status=vencidos')->assertJsonCount(1, 'data');
        $this->actingAs($this->teacher, 'sanctum')->getJson('/api/resource-loans?search=portátil')->assertJsonPath('data.0.id', $toTeacher);

        // Debe elegirse exactamente un prestatario.
        $this->actingAs($this->teacher, 'sanctum')->postJson('/api/resource-loans', [
            'category' => 'libro', 'item_name' => 'Álgebra', 'loan_date' => now()->toDateString(), 'due_date' => now()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_return_reopen_and_who_can_manage_a_loan(): void
    {
        $id = $this->lend($this->teacher);

        // Otro docente no lo ve en su lista ni lo puede tocar; el admin sí.
        $this->actingAs($this->colleague, 'sanctum')->getJson('/api/resource-loans')->assertJsonCount(0, 'data');
        $this->actingAs($this->colleague, 'sanctum')->postJson("/api/resource-loans/{$id}/return", ['status' => 'devuelto'])->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/resource-loans')->assertJsonCount(1, 'data');

        $this->actingAs($this->teacher, 'sanctum')->postJson("/api/resource-loans/{$id}/return", [
            'status' => 'devuelto', 'return_condition' => 'danado', 'return_notes' => 'Pantalla rayada',
        ])->assertOk()->assertJsonPath('data.status', 'devuelto')->assertJsonPath('data.is_overdue', false);
        $this->actingAs($this->teacher, 'sanctum')->getJson('/api/resource-loans')->assertJsonCount(0, 'data');
        $this->actingAs($this->teacher, 'sanctum')->getJson('/api/resource-loans?status=devueltos')->assertJsonCount(1, 'data');

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/resource-loans/{$id}/reopen")
            ->assertOk()->assertJsonPath('data.status', 'prestado')->assertJsonPath('data.returned_at', null);

        $this->actingAs($this->teacher, 'sanctum')->putJson("/api/resource-loans/{$id}", ['due_date' => now()->subDays(20)->toDateString()])
            ->assertStatus(422);
        $this->actingAs($this->teacher, 'sanctum')->deleteJson("/api/resource-loans/{$id}")->assertOk();
        $this->assertSame(0, ResourceLoan::count());
    }

    public function test_alerts_and_the_student_profile_show_pending_loans(): void
    {
        $this->lend($this->teacher, ['due_date' => now()->subDay()->toDateString()]);
        $this->lend($this->teacher, [
            'student_id' => null, 'borrower_user_id' => $this->colleague->id, 'category' => 'libro', 'item_name' => 'Cálculo de Stewart',
            'due_date' => now()->subDays(2)->toDateString(),
        ]);

        $this->actingAs($this->teacher, 'sanctum')->getJson('/api/resource-loans/alerts')
            ->assertOk()->assertJsonPath('overdue_lent', 2)->assertJsonCount(0, 'borrowed');
        // El docente que lo tiene lo ve como algo que debe devolver.
        $this->actingAs($this->colleague, 'sanctum')->getJson('/api/resource-loans/alerts')
            ->assertJsonPath('overdue_lent', 0)
            ->assertJsonPath('borrowed.0.item_name', 'Cálculo de Stewart')
            ->assertJsonPath('borrowed.0.is_overdue', true);

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/students/{$this->student->id}/full-profile")
            ->assertOk()->assertJsonPath('loans.0.item_name', 'micro:bit')->assertJsonPath('loans.0.is_overdue', true);

        $this->actingAs($this->teacher, 'sanctum')->getJson('/api/resource-loans/teachers')->assertOk()->assertJsonCount(3, 'data');
    }
}
