<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Group;
use App\Models\GroupSubject;
use App\Models\Institution;
use App\Models\InstitutionTeacher;
use App\Models\Period;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InstitutionLogoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->admin = User::factory()->create();
        $this->institution = Institution::create([
            'name' => 'Colegio Test', 'city' => 'Cali', 'department' => 'Valle',
            'min_passing_grade' => 6.0, 'created_by' => $this->admin->id,
        ]);
        InstitutionTeacher::create([
            'institution_id' => $this->institution->id, 'user_id' => $this->admin->id, 'role' => 'admin', 'status' => 'active',
        ]);
    }

    public function test_admin_can_upload_and_replace_the_institution_logo(): void
    {
        $first = UploadedFile::fake()->image('logo.png', 100, 100);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson("/api/institutions/{$this->institution->id}", [
            '_method' => 'PUT',
            'logo' => $first,
        ]);

        $response->assertOk();
        $firstPath = $response->json('data.logo');
        $this->assertNotNull($firstPath);
        Storage::disk('local')->assertExists($firstPath);

        $second = UploadedFile::fake()->image('logo2.png', 100, 100);
        $response = $this->actingAs($this->admin, 'sanctum')->postJson("/api/institutions/{$this->institution->id}", [
            '_method' => 'PUT',
            'logo' => $second,
        ]);

        $response->assertOk();
        $secondPath = $response->json('data.logo');
        $this->assertNotEquals($firstPath, $secondPath);
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($secondPath);
    }

    public function test_a_non_admin_teacher_cannot_upload_a_logo_but_can_view_it(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/institutions/{$this->institution->id}", [
            '_method' => 'PUT',
            'logo' => UploadedFile::fake()->image('logo.png', 100, 100),
        ])->assertOk();

        $teacher = User::factory()->create();
        InstitutionTeacher::create([
            'institution_id' => $this->institution->id, 'user_id' => $teacher->id, 'role' => 'teacher', 'status' => 'active',
        ]);

        $this->actingAs($teacher, 'sanctum')->postJson("/api/institutions/{$this->institution->id}", [
            '_method' => 'PUT',
            'logo' => UploadedFile::fake()->image('logo.png', 100, 100),
        ])->assertForbidden();

        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/institutions/{$this->institution->id}/logo")
            ->assertOk();
    }

    public function test_a_user_who_is_not_a_member_cannot_view_the_logo(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/institutions/{$this->institution->id}", [
            '_method' => 'PUT',
            'logo' => UploadedFile::fake()->image('logo.png', 100, 100),
        ])->assertOk();

        $outsider = User::factory()->create();

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/institutions/{$this->institution->id}/logo")
            ->assertForbidden();
    }

    public function test_grade_sheet_report_generates_successfully_with_a_logo_configured(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/institutions/{$this->institution->id}", [
            '_method' => 'PUT',
            'logo' => UploadedFile::fake()->image('logo.png', 100, 100),
        ])->assertOk();

        $academicYear = AcademicYear::create([
            'institution_id' => $this->institution->id, 'year' => 2026,
            'start_date' => '2026-01-20', 'end_date' => '2026-11-28',
        ]);
        $period = Period::create([
            'academic_year_id' => $academicYear->id, 'number' => 1, 'name' => 'Primer Período',
            'start_date' => '2026-01-20', 'end_date' => '2026-03-20', 'is_active' => true,
        ]);
        $group = Group::create([
            'institution_id' => $this->institution->id, 'academic_year_id' => $academicYear->id,
            'name' => '10-1', 'grade_level' => '10',
        ]);
        $subject = Subject::create(['institution_id' => $this->institution->id, 'name' => 'Matemáticas']);
        $groupSubject = GroupSubject::create([
            'group_id' => $group->id, 'subject_id' => $subject->id, 'user_id' => $this->admin->id,
            'institution_id' => $this->institution->id, 'academic_year_id' => $academicYear->id,
        ]);

        $reportId = $this->actingAs($this->admin, 'sanctum')->postJson('/api/reports/grade-sheet', [
            'group_subject_id' => $groupSubject->id,
            'period_id' => $period->id,
            'format' => 'excel',
        ])->assertStatus(202)->json('data.id');

        $report = \App\Models\Report::findOrFail($reportId);
        $this->assertEquals('completed', $report->status);
        Storage::disk('local')->assertExists($report->file_path);
    }
}
