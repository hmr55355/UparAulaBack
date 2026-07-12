<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\BehaviorAnnotation;
use App\Models\Grade;
use App\Models\GradeColumn;
use App\Models\GradeSection;
use App\Models\Group;
use App\Models\GroupSubject;
use App\Models\Institution;
use App\Models\InstitutionTeacher;
use App\Models\ParentCitation;
use App\Models\ParentGuardian;
use App\Models\Period;
use App\Models\Report;
use App\Models\Student;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportGenerationTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Institution $institution;

    private AcademicYear $academicYear;

    private Period $period;

    private Group $group;

    private GroupSubject $groupSubject;

    private Student $studentA;

    private Student $studentB;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->teacher = User::factory()->create();
        $this->institution = Institution::create([
            'name' => 'Colegio Test', 'city' => 'Cali', 'department' => 'Valle',
            'min_passing_grade' => 6.0, 'created_by' => $this->teacher->id,
        ]);
        $this->academicYear = AcademicYear::create([
            'institution_id' => $this->institution->id, 'year' => 2026,
            'start_date' => '2026-01-20', 'end_date' => '2026-11-28',
        ]);
        $this->period = Period::create([
            'academic_year_id' => $this->academicYear->id, 'number' => 1, 'name' => 'Primer Período',
            'start_date' => '2026-01-20', 'end_date' => '2026-03-20', 'is_active' => true,
        ]);
        $this->group = Group::create([
            'institution_id' => $this->institution->id, 'academic_year_id' => $this->academicYear->id,
            'name' => '10-1', 'grade_level' => '10',
        ]);
        $subject = Subject::create(['institution_id' => $this->institution->id, 'name' => 'Matemáticas']);
        $this->groupSubject = GroupSubject::create([
            'group_id' => $this->group->id, 'subject_id' => $subject->id, 'user_id' => $this->teacher->id,
            'institution_id' => $this->institution->id, 'academic_year_id' => $this->academicYear->id,
        ]);
        InstitutionTeacher::create([
            'institution_id' => $this->institution->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'status' => 'active',
        ]);

        $this->studentA = Student::create(['institution_id' => $this->institution->id, 'first_name' => 'Ana', 'last_name' => 'Gómez']);
        $this->studentB = Student::create(['institution_id' => $this->institution->id, 'first_name' => 'Luis', 'last_name' => 'Ramírez']);
        foreach ([$this->studentA, $this->studentB] as $student) {
            StudentGroup::create([
                'student_id' => $student->id, 'group_id' => $this->group->id, 'academic_year_id' => $this->academicYear->id,
                'enrollment_date' => '2026-01-20', 'status' => 'activo',
            ]);
        }

        $section = GradeSection::create([
            'group_subject_id' => $this->groupSubject->id, 'period_id' => $this->period->id,
            'name' => 'Aptitud', 'weight' => 100, 'has_section_final' => true, 'final_calculation' => 'weighted_avg', 'sort_order' => 0,
        ]);
        $column = GradeColumn::create([
            'grade_section_id' => $section->id, 'group_subject_id' => $this->groupSubject->id, 'period_id' => $this->period->id,
            'column_type' => 'manual', 'name' => 'Taller', 'weight' => 100, 'max_score' => 10, 'sort_order' => 0,
        ]);

        Grade::create([
            'student_id' => $this->studentA->id, 'grade_column_id' => $column->id, 'group_subject_id' => $this->groupSubject->id,
            'period_id' => $this->period->id, 'score' => 9.0, 'registered_by' => $this->teacher->id,
        ]);
        Grade::create([
            'student_id' => $this->studentB->id, 'grade_column_id' => $column->id, 'group_subject_id' => $this->groupSubject->id,
            'period_id' => $this->period->id, 'score' => 4.0, 'registered_by' => $this->teacher->id,
        ]);
        // No SectionFinal/PeriodFinal creation here: GradeObserver::saved() already
        // recalculates both automatically from the Grade rows above (Phase 2 cascade).

        AttendanceRecord::create([
            'student_id' => $this->studentA->id, 'group_subject_id' => $this->groupSubject->id,
            'date' => '2026-02-02', 'status' => 'presente', 'registered_by' => $this->teacher->id,
        ]);
        AttendanceRecord::create([
            'student_id' => $this->studentB->id, 'group_subject_id' => $this->groupSubject->id,
            'date' => '2026-02-02', 'status' => 'ausente_injustificado', 'registered_by' => $this->teacher->id,
        ]);
    }

    private function assertReportCompletedAndDownloadable(int $reportId): Report
    {
        $report = Report::findOrFail($reportId);
        $this->assertEquals('completed', $report->status);
        $this->assertNotNull($report->file_path);
        Storage::disk('local')->assertExists($report->file_path);

        return $report;
    }

    public function test_grade_sheet_report_generates_an_excel_file(): void
    {
        $reportId = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/reports/grade-sheet', [
            'group_subject_id' => $this->groupSubject->id,
            'period_id' => $this->period->id,
            'format' => 'excel',
        ])->assertStatus(202)->json('data.id');

        $report = $this->assertReportCompletedAndDownloadable($reportId);
        $this->assertStringEndsWith('.xlsx', $report->file_path);

        $this->actingAs($this->teacher, 'sanctum')->get("/api/reports/{$reportId}/download")->assertOk();
    }

    public function test_grade_sheet_report_as_pdf(): void
    {
        $reportId = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/reports/grade-sheet', [
            'group_subject_id' => $this->groupSubject->id,
            'period_id' => $this->period->id,
            'format' => 'pdf',
        ])->assertStatus(202)->json('data.id');

        $report = $this->assertReportCompletedAndDownloadable($reportId);
        $this->assertStringEndsWith('.pdf', $report->file_path);
    }

    public function test_student_bulletin_report_generates_a_pdf_file(): void
    {
        $reportId = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/reports/student-bulletin', [
            'student_id' => $this->studentA->id,
            'period_id' => $this->period->id,
        ])->assertStatus(202)->json('data.id');

        $report = $this->assertReportCompletedAndDownloadable($reportId);
        $this->assertStringEndsWith('.pdf', $report->file_path);
    }

    public function test_attendance_sheet_report_generates_a_file(): void
    {
        $reportId = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/reports/attendance-sheet', [
            'group_subject_id' => $this->groupSubject->id,
            'period_id' => $this->period->id,
            'format' => 'excel',
        ])->assertStatus(202)->json('data.id');

        $this->assertReportCompletedAndDownloadable($reportId);
    }

    public function test_behavior_citations_report_generates_a_file(): void
    {
        BehaviorAnnotation::create([
            'student_id' => $this->studentA->id, 'group_subject_id' => $this->groupSubject->id, 'group_id' => $this->group->id,
            'registered_by' => $this->teacher->id, 'date' => '2026-02-03', 'type' => 'negativa', 'category' => 'convivencia',
            'title' => 'Interrumpe la clase', 'description' => 'Interrumpió la clase reiteradamente.',
            'requires_parent_contact' => true, 'parent_contacted' => false,
        ]);
        $parent = ParentGuardian::create([
            'institution_id' => $this->institution->id, 'first_name' => 'Marta', 'last_name' => 'Gómez',
            'relationship' => 'madre', 'phone' => '3000000000',
        ]);
        ParentCitation::create([
            'student_id' => $this->studentA->id, 'parent_id' => $parent->id, 'group_id' => $this->group->id,
            'registered_by' => $this->teacher->id, 'citation_type' => 'comportamiento', 'reason' => 'Seguimiento disciplinario',
            'scheduled_date' => '2026-02-10 08:00:00', 'status' => 'pendiente',
        ]);

        $reportId = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/reports/behavior-citations', [
            'group_id' => $this->group->id,
            'period_id' => $this->period->id,
            'format' => 'excel',
        ])->assertStatus(202)->json('data.id');

        $this->assertReportCompletedAndDownloadable($reportId);
    }

    public function test_academic_risk_report_generates_a_file(): void
    {
        $reportId = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/reports/academic-risk', [
            'institution_id' => $this->institution->id,
            'period_id' => $this->period->id,
            'format' => 'excel',
        ])->assertStatus(202)->json('data.id');

        $this->assertReportCompletedAndDownloadable($reportId);
    }

    public function test_copies_summary_report_generates_an_excel_file(): void
    {
        $chargeId = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/copy-charges', [
            'group_id' => $this->group->id, 'description' => 'Guía', 'quantity' => 10, 'unit_price' => 200,
            'charge_date' => '2026-02-01',
        ])->json('data.id');
        $this->assertNotNull($chargeId);

        $reportId = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/copy-charges/summary', [
            'group_id' => $this->group->id,
        ])->assertStatus(202)->json('data.id');

        $report = $this->assertReportCompletedAndDownloadable($reportId);
        $this->assertStringEndsWith('.xlsx', $report->file_path);
    }

    public function test_a_teacher_who_doesnt_teach_the_course_is_forbidden_from_course_scoped_reports(): void
    {
        $outsider = User::factory()->create();
        InstitutionTeacher::create([
            'institution_id' => $this->institution->id, 'user_id' => $outsider->id, 'role' => 'teacher', 'status' => 'active',
        ]);

        $this->actingAs($outsider, 'sanctum')->postJson('/api/reports/grade-sheet', [
            'group_subject_id' => $this->groupSubject->id, 'period_id' => $this->period->id, 'format' => 'excel',
        ])->assertForbidden();

        $this->actingAs($outsider, 'sanctum')->postJson('/api/reports/attendance-sheet', [
            'group_subject_id' => $this->groupSubject->id, 'period_id' => $this->period->id, 'format' => 'excel',
        ])->assertForbidden();
    }

    public function test_a_teacher_with_no_course_in_the_group_can_still_generate_group_wide_reports(): void
    {
        $otherTeacher = User::factory()->create();
        InstitutionTeacher::create([
            'institution_id' => $this->institution->id, 'user_id' => $otherTeacher->id, 'role' => 'teacher', 'status' => 'active',
        ]);

        $this->actingAs($otherTeacher, 'sanctum')->postJson('/api/reports/behavior-citations', [
            'group_id' => $this->group->id, 'period_id' => $this->period->id, 'format' => 'excel',
        ])->assertStatus(202);

        $this->actingAs($otherTeacher, 'sanctum')->postJson('/api/reports/academic-risk', [
            'institution_id' => $this->institution->id, 'period_id' => $this->period->id, 'format' => 'excel',
        ])->assertStatus(202);

        $this->actingAs($otherTeacher, 'sanctum')->postJson('/api/copy-charges/summary', [
            'group_id' => $this->group->id,
        ])->assertStatus(202);
    }

    public function test_download_is_forbidden_for_a_user_who_isnt_the_report_owner(): void
    {
        $reportId = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/reports/grade-sheet', [
            'group_subject_id' => $this->groupSubject->id, 'period_id' => $this->period->id, 'format' => 'excel',
        ])->json('data.id');

        $otherTeacher = User::factory()->create();
        $this->actingAs($otherTeacher, 'sanctum')->get("/api/reports/{$reportId}/download")->assertForbidden();
    }

    public function test_download_returns_409_while_the_report_is_not_completed(): void
    {
        $report = Report::create([
            'user_id' => $this->teacher->id, 'institution_id' => $this->institution->id,
            'type' => 'grade_sheet', 'format' => 'excel', 'params' => [], 'status' => 'processing',
        ]);

        $this->actingAs($this->teacher, 'sanctum')->get("/api/reports/{$report->id}/download")->assertStatus(409);
    }
}
