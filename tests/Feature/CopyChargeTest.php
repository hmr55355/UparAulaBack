<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Group;
use App\Models\Institution;
use App\Models\InstitutionTeacher;
use App\Models\Student;
use App\Models\StudentGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CopyChargeTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Institution $institution;

    private Group $group;

    private Student $studentA;

    private Student $studentB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->institution = Institution::create([
            'name' => 'Colegio Test', 'city' => 'Cali', 'department' => 'Valle',
            'min_passing_grade' => 6.0, 'created_by' => $this->teacher->id,
        ]);
        $academicYear = AcademicYear::create([
            'institution_id' => $this->institution->id, 'year' => 2026,
            'start_date' => '2026-01-20', 'end_date' => '2026-11-28',
        ]);
        $this->group = Group::create([
            'institution_id' => $this->institution->id, 'academic_year_id' => $academicYear->id,
            'name' => '10-1', 'grade_level' => '10',
        ]);
        InstitutionTeacher::create([
            'institution_id' => $this->institution->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'status' => 'active',
        ]);

        $this->studentA = Student::create(['institution_id' => $this->institution->id, 'first_name' => 'Ana', 'last_name' => 'Gómez']);
        $this->studentB = Student::create(['institution_id' => $this->institution->id, 'first_name' => 'Luis', 'last_name' => 'Ramírez']);
        foreach ([$this->studentA, $this->studentB] as $student) {
            StudentGroup::create([
                'student_id' => $student->id, 'group_id' => $this->group->id, 'academic_year_id' => $academicYear->id,
                'enrollment_date' => '2026-01-20', 'status' => 'activo',
            ]);
        }
    }

    public function test_creating_a_charge_generates_debe_payments_for_all_active_students(): void
    {
        $response = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/copy-charges', [
            'group_id' => $this->group->id,
            'description' => 'Guía de Trigonometría',
            'quantity' => 10,
            'unit_price' => 200,
            'charge_date' => '2026-02-01',
        ]);

        $response->assertCreated();
        $this->assertEquals(2000, $response->json('data.total_amount'));
        $chargeId = $response->json('data.id');

        $this->assertDatabaseHas('student_copy_payments', [
            'copy_charge_id' => $chargeId, 'student_id' => $this->studentA->id, 'status' => 'debe',
        ]);
        $this->assertDatabaseHas('student_copy_payments', [
            'copy_charge_id' => $chargeId, 'student_id' => $this->studentB->id, 'status' => 'debe',
        ]);
    }

    public function test_registering_payments_updates_status_correctly(): void
    {
        $chargeId = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/copy-charges', [
            'group_id' => $this->group->id,
            'description' => 'Guía de Trigonometría',
            'quantity' => 10,
            'unit_price' => 200,
            'charge_date' => '2026-02-01',
        ])->json('data.id');

        $this->actingAs($this->teacher, 'sanctum')->postJson("/api/copy-charges/{$chargeId}/payments/bulk", [
            'payments' => [
                ['student_id' => $this->studentA->id, 'status' => 'pagado', 'amount_paid' => 2000],
                ['student_id' => $this->studentB->id, 'status' => 'pago_parcial', 'amount_paid' => 1000],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('student_copy_payments', [
            'copy_charge_id' => $chargeId, 'student_id' => $this->studentA->id, 'status' => 'pagado', 'amount_paid' => 2000,
        ]);
        $this->assertDatabaseHas('student_copy_payments', [
            'copy_charge_id' => $chargeId, 'student_id' => $this->studentB->id, 'status' => 'pago_parcial', 'amount_paid' => 1000,
        ]);

        // Idempotente: volver a guardar el mismo estudiante actualiza, no duplica.
        $this->actingAs($this->teacher, 'sanctum')->postJson("/api/copy-charges/{$chargeId}/payments/bulk", [
            'payments' => [['student_id' => $this->studentB->id, 'status' => 'pagado', 'amount_paid' => 2000]],
        ])->assertCreated();

        $this->assertDatabaseCount('student_copy_payments', 2);
        $this->assertDatabaseHas('student_copy_payments', [
            'copy_charge_id' => $chargeId, 'student_id' => $this->studentB->id, 'status' => 'pagado', 'amount_paid' => 2000,
        ]);
    }

    public function test_a_teacher_who_doesnt_teach_any_course_of_the_group_can_still_manage_its_charges(): void
    {
        // Deliberadamente NO se crea ningún group_subject para este docente: el
        // modelo de autorización de copias es institución-wide, no por curso.
        $otherTeacher = User::factory()->create();
        InstitutionTeacher::create([
            'institution_id' => $this->institution->id, 'user_id' => $otherTeacher->id, 'role' => 'teacher', 'status' => 'active',
        ]);

        $response = $this->actingAs($otherTeacher, 'sanctum')->postJson('/api/copy-charges', [
            'group_id' => $this->group->id,
            'description' => 'Copias de emergencia',
            'quantity' => 5,
            'unit_price' => 100,
            'charge_date' => '2026-02-01',
        ]);

        $response->assertCreated();
    }

    public function test_a_user_who_is_not_a_member_of_the_institution_is_forbidden(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider, 'sanctum')->postJson('/api/copy-charges', [
            'group_id' => $this->group->id,
            'description' => 'Intruso',
            'quantity' => 5,
            'unit_price' => 100,
            'charge_date' => '2026-02-01',
        ])->assertForbidden();

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/copy-charges?groupId={$this->group->id}")
            ->assertForbidden();
    }

    public function test_pending_amount_accounts_for_every_student_owing_the_full_total(): void
    {
        // Cada uno de los 2 estudiantes activos debe el total_amount completo
        // (2000 cada uno) — lo recaudable total del cobro es 4000, no 2000.
        $chargeId = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/copy-charges', [
            'group_id' => $this->group->id,
            'description' => 'Guía',
            'quantity' => 10,
            'unit_price' => 200,
            'charge_date' => '2026-02-01',
        ])->json('data.id');

        $this->actingAs($this->teacher, 'sanctum')->postJson("/api/copy-charges/{$chargeId}/payments/bulk", [
            'payments' => [
                ['student_id' => $this->studentA->id, 'status' => 'pagado', 'amount_paid' => 2000],
                ['student_id' => $this->studentB->id, 'status' => 'pago_parcial', 'amount_paid' => 1000],
            ],
        ])->assertCreated();

        $response = $this->actingAs($this->teacher, 'sanctum')->getJson("/api/copy-charges?groupId={$this->group->id}");

        $response->assertOk();
        $this->assertEquals(1, $response->json('data.0.paid_count'));
        $this->assertEquals(2, $response->json('data.0.total_students'));
        $this->assertEquals(1000, $response->json('data.0.pending_amount'));
    }
}
