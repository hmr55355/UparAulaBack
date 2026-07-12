<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Institution;
use App\Models\InstitutionTeacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_export_year_data_as_json(): void
    {
        $admin = User::factory()->create();
        $institution = Institution::create([
            'name' => 'Colegio Test', 'city' => 'Cali', 'department' => 'Valle',
            'min_passing_grade' => 6.0, 'created_by' => $admin->id,
        ]);
        $academicYear = AcademicYear::create([
            'institution_id' => $institution->id, 'year' => 2026,
            'start_date' => '2026-01-20', 'end_date' => '2026-11-28',
        ]);
        InstitutionTeacher::create([
            'institution_id' => $institution->id, 'user_id' => $admin->id, 'role' => 'admin', 'status' => 'active',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/academic-years/{$academicYear->id}/export");

        $response->assertOk();
        $response->assertJsonStructure(['academic_year', 'groups', 'group_subjects', 'students', 'period_finals', 'attendance_records']);
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    }

    public function test_a_non_admin_teacher_is_forbidden_from_exporting(): void
    {
        $teacher = User::factory()->create();
        $institution = Institution::create([
            'name' => 'Colegio Test', 'city' => 'Cali', 'department' => 'Valle',
            'min_passing_grade' => 6.0, 'created_by' => $teacher->id,
        ]);
        $academicYear = AcademicYear::create([
            'institution_id' => $institution->id, 'year' => 2026,
            'start_date' => '2026-01-20', 'end_date' => '2026-11-28',
        ]);
        InstitutionTeacher::create([
            'institution_id' => $institution->id, 'user_id' => $teacher->id, 'role' => 'teacher', 'status' => 'active',
        ]);

        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/academic-years/{$academicYear->id}/export")
            ->assertForbidden();
    }
}
