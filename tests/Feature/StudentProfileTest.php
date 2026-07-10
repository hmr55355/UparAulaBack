<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Group;
use App\Models\GroupSubject;
use App\Models\Institution;
use App\Models\InstitutionTeacher;
use App\Models\Period;
use App\Models\PeriodFinal;
use App\Models\Student;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $teacherA;

    private User $teacherB;

    private Institution $institution;

    private Group $group;

    private Student $student;

    private GroupSubject $groupSubjectA;

    private GroupSubject $groupSubjectB;

    private Period $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacherA = User::factory()->create();
        $this->teacherB = User::factory()->create();
        $this->institution = Institution::create([
            'name' => 'Colegio Test', 'city' => 'Cali', 'department' => 'Valle', 'created_by' => $this->teacherA->id,
        ]);
        InstitutionTeacher::create([
            'institution_id' => $this->institution->id, 'user_id' => $this->teacherA->id, 'role' => 'teacher', 'status' => 'active',
        ]);
        InstitutionTeacher::create([
            'institution_id' => $this->institution->id, 'user_id' => $this->teacherB->id, 'role' => 'teacher', 'status' => 'active',
        ]);
        $academicYear = AcademicYear::create([
            'institution_id' => $this->institution->id, 'year' => 2026,
            'start_date' => '2026-01-20', 'end_date' => '2026-11-28',
        ]);
        $this->period = Period::create([
            'academic_year_id' => $academicYear->id, 'number' => 1, 'name' => 'Primer Período',
            'start_date' => '2026-01-20', 'end_date' => '2026-03-20', 'is_active' => true,
        ]);
        $this->group = Group::create([
            'institution_id' => $this->institution->id, 'academic_year_id' => $academicYear->id,
            'name' => '10-1', 'grade_level' => '10',
        ]);
        $subjectA = Subject::create(['institution_id' => $this->institution->id, 'name' => 'Matemáticas']);
        $subjectB = Subject::create(['institution_id' => $this->institution->id, 'name' => 'Español']);
        $this->groupSubjectA = GroupSubject::create([
            'group_id' => $this->group->id, 'subject_id' => $subjectA->id, 'user_id' => $this->teacherA->id,
            'institution_id' => $this->institution->id, 'academic_year_id' => $academicYear->id,
        ]);
        $this->groupSubjectB = GroupSubject::create([
            'group_id' => $this->group->id, 'subject_id' => $subjectB->id, 'user_id' => $this->teacherB->id,
            'institution_id' => $this->institution->id, 'academic_year_id' => $academicYear->id,
        ]);

        $this->student = Student::create(['institution_id' => $this->institution->id, 'first_name' => 'Ana', 'last_name' => 'Gómez']);
        StudentGroup::create([
            'student_id' => $this->student->id, 'group_id' => $this->group->id, 'academic_year_id' => $academicYear->id,
            'enrollment_date' => '2026-01-20', 'status' => 'activo',
        ]);

        PeriodFinal::create([
            'student_id' => $this->student->id, 'group_subject_id' => $this->groupSubjectA->id, 'period_id' => $this->period->id,
            'period_final' => 8.5, 'is_promoted' => true,
        ]);
        PeriodFinal::create([
            'student_id' => $this->student->id, 'group_subject_id' => $this->groupSubjectB->id, 'period_id' => $this->period->id,
            'period_final' => 7.0, 'is_promoted' => true,
        ]);
    }

    public function test_full_profile_only_includes_grades_the_requesting_teacher_actually_teaches(): void
    {
        $response = $this->actingAs($this->teacherA, 'sanctum')->getJson("/api/students/{$this->student->id}/full-profile");

        $response->assertOk();
        $grades = $response->json('grades');
        $this->assertCount(1, $grades);
        $this->assertEquals('Matemáticas', $grades[0]['subject_name']);
        $this->assertEquals(8.5, (float) $grades[0]['period_final']);
    }

    public function test_a_teacher_who_doesnt_teach_the_student_cannot_view_the_profile(): void
    {
        $outsider = User::factory()->create();
        InstitutionTeacher::create([
            'institution_id' => $this->institution->id, 'user_id' => $outsider->id, 'role' => 'teacher', 'status' => 'active',
        ]);

        $response = $this->actingAs($outsider, 'sanctum')->getJson("/api/students/{$this->student->id}/full-profile");

        $response->assertForbidden();
    }
}
