<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\GradeColumn;
use App\Models\GradeSection;
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

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private GroupSubject $groupSubject;

    private Period $period;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $institution = Institution::create([
            'name' => 'Colegio Test', 'city' => 'Cali', 'department' => 'Valle', 'created_by' => $this->teacher->id,
        ]);
        InstitutionTeacher::create([
            'institution_id' => $institution->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'status' => 'active',
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

        $this->student = Student::create(['institution_id' => $institution->id, 'first_name' => 'Ana', 'last_name' => 'Gómez']);
        StudentGroup::create([
            'student_id' => $this->student->id, 'group_id' => $group->id, 'academic_year_id' => $academicYear->id,
            'enrollment_date' => '2026-01-20', 'status' => 'activo',
        ]);
    }

    public function test_bulk_is_idempotent_when_resaving_the_same_day(): void
    {
        $payload = [
            'group_subject_id' => $this->groupSubject->id,
            'date' => '2026-02-02',
            'records' => [['student_id' => $this->student->id, 'status' => 'ausente_injustificado']],
        ];

        $this->actingAs($this->teacher, 'sanctum')->postJson('/api/attendance/bulk', $payload)->assertCreated();
        $this->assertDatabaseCount('attendance_records', 1);

        // El docente corrige el estado del mismo estudiante, mismo día: debe actualizar, no duplicar.
        $payload['records'][0]['status'] = 'presente';
        $this->actingAs($this->teacher, 'sanctum')->postJson('/api/attendance/bulk', $payload)->assertCreated();

        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertDatabaseHas('attendance_records', ['student_id' => $this->student->id, 'status' => 'presente']);
    }

    public function test_bulk_saves_attendance_for_multiple_students(): void
    {
        $student2 = Student::create(['institution_id' => $this->groupSubject->institution_id, 'first_name' => 'Luis', 'last_name' => 'Ramírez']);
        StudentGroup::create([
            'student_id' => $student2->id, 'group_id' => $this->groupSubject->group_id,
            'academic_year_id' => $this->groupSubject->academic_year_id, 'enrollment_date' => '2026-01-20', 'status' => 'activo',
        ]);

        $response = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/attendance/bulk', [
            'group_subject_id' => $this->groupSubject->id,
            'date' => '2026-02-02',
            'records' => [
                ['student_id' => $this->student->id, 'status' => 'presente'],
                ['student_id' => $student2->id, 'status' => 'ausente_injustificado'],
            ],
        ]);

        $response->assertCreated()->assertJsonPath('count', 2);
        $this->assertDatabaseHas('attendance_records', ['student_id' => $this->student->id, 'status' => 'presente']);
        $this->assertDatabaseHas('attendance_records', ['student_id' => $student2->id, 'status' => 'ausente_injustificado']);
    }

    public function test_saving_an_absence_automatically_recalculates_the_attendance_grade_column(): void
    {
        $section = GradeSection::create([
            'group_subject_id' => $this->groupSubject->id, 'period_id' => $this->period->id,
            'name' => 'Aptitud', 'weight' => 100, 'final_calculation' => 'weighted_avg',
        ]);
        $attendanceColumn = GradeColumn::create([
            'grade_section_id' => $section->id, 'group_subject_id' => $this->groupSubject->id, 'period_id' => $this->period->id,
            'column_type' => 'from_attendance', 'name' => 'Asistencia', 'short_name' => 'Asist', 'weight' => 100,
            'attendance_base_score' => 10.0, 'absence_penalty' => 0.5, 'justified_absence_penalty' => 0.1,
        ]);

        $this->actingAs($this->teacher, 'sanctum')->postJson('/api/attendance/bulk', [
            'group_subject_id' => $this->groupSubject->id,
            'date' => '2026-02-02',
            'records' => [
                ['student_id' => $this->student->id, 'status' => 'ausente_injustificado'],
            ],
        ])->assertCreated();

        // Sin llamar a ningún endpoint de recálculo: el AttendanceObserver ya debió actuar.
        $grade = Grade::where('student_id', $this->student->id)->where('grade_column_id', $attendanceColumn->id)->first();

        $this->assertNotNull($grade);
        $this->assertEquals(9.5, (float) $grade->score);
    }

    public function test_stats_counts_absences_per_student(): void
    {
        $this->actingAs($this->teacher, 'sanctum')->postJson('/api/attendance/bulk', [
            'group_subject_id' => $this->groupSubject->id,
            'date' => '2026-02-02',
            'records' => [['student_id' => $this->student->id, 'status' => 'ausente_injustificado']],
        ])->assertCreated();

        $this->actingAs($this->teacher, 'sanctum')->postJson('/api/attendance/bulk', [
            'group_subject_id' => $this->groupSubject->id,
            'date' => '2026-02-09',
            'records' => [['student_id' => $this->student->id, 'status' => 'ausente_injustificado']],
        ])->assertCreated();

        $response = $this->actingAs($this->teacher, 'sanctum')->getJson(
            "/api/attendance/stats?groupSubjectId={$this->groupSubject->id}&periodId={$this->period->id}"
        );

        $response->assertOk();
        $this->assertEquals($this->student->id, $response->json('data.0.student_id'));
        $this->assertEquals(2, $response->json('data.0.ausente_injustificado'));
    }

    public function test_cannot_save_attendance_for_a_closed_period(): void
    {
        $this->period->update(['is_closed' => true]);

        $response = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/attendance/bulk', [
            'group_subject_id' => $this->groupSubject->id,
            'date' => '2026-02-02',
            'records' => [['student_id' => $this->student->id, 'status' => 'presente']],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_a_teacher_who_doesnt_own_the_course_cannot_save_attendance(): void
    {
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider, 'sanctum')->postJson('/api/attendance/bulk', [
            'group_subject_id' => $this->groupSubject->id,
            'date' => '2026-02-02',
            'records' => [['student_id' => $this->student->id, 'status' => 'presente']],
        ]);

        $response->assertForbidden();
    }

    public function test_day_view_preloads_existing_records(): void
    {
        $this->actingAs($this->teacher, 'sanctum')->postJson('/api/attendance/bulk', [
            'group_subject_id' => $this->groupSubject->id,
            'date' => '2026-02-02',
            'records' => [['student_id' => $this->student->id, 'status' => 'tarde']],
        ])->assertCreated();

        $response = $this->actingAs($this->teacher, 'sanctum')->getJson(
            "/api/attendance?groupSubjectId={$this->groupSubject->id}&date=2026-02-02"
        );

        $response->assertOk();
        $records = $response->json('records');
        $this->assertEquals('tarde', $records[$this->student->id]['status']);
    }
}
