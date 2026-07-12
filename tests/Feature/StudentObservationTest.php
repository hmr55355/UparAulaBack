<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Group;
use App\Models\GroupSubject;
use App\Models\Institution;
use App\Models\InstitutionTeacher;
use App\Models\Student;
use App\Models\StudentGroup;
use App\Models\StudentObservation;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentObservationTest extends TestCase
{
    use RefreshDatabase;

    private User $teacherA;

    private User $teacherB;

    private Institution $institution;

    private Group $group;

    private Student $student;

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
        $this->group = Group::create([
            'institution_id' => $this->institution->id, 'academic_year_id' => $academicYear->id,
            'name' => '10-1', 'grade_level' => '10',
        ]);
        $subjectA = Subject::create(['institution_id' => $this->institution->id, 'name' => 'Matemáticas']);
        $subjectB = Subject::create(['institution_id' => $this->institution->id, 'name' => 'Español']);
        GroupSubject::create([
            'group_id' => $this->group->id, 'subject_id' => $subjectA->id, 'user_id' => $this->teacherA->id,
            'institution_id' => $this->institution->id, 'academic_year_id' => $academicYear->id,
        ]);
        GroupSubject::create([
            'group_id' => $this->group->id, 'subject_id' => $subjectB->id, 'user_id' => $this->teacherB->id,
            'institution_id' => $this->institution->id, 'academic_year_id' => $academicYear->id,
        ]);

        $this->student = Student::create(['institution_id' => $this->institution->id, 'first_name' => 'Ana', 'last_name' => 'Gómez']);
        StudentGroup::create([
            'student_id' => $this->student->id, 'group_id' => $this->group->id, 'academic_year_id' => $academicYear->id,
            'enrollment_date' => '2026-01-20', 'status' => 'activo',
        ]);
    }

    public function test_a_private_observation_is_only_visible_to_its_author(): void
    {
        StudentObservation::create([
            'student_id' => $this->student->id, 'group_id' => $this->group->id, 'registered_by' => $this->teacherA->id,
            'date' => '2026-02-05', 'type' => 'seguimiento', 'content' => 'Nota privada de A.', 'is_private' => true,
        ]);

        $ownerView = $this->actingAs($this->teacherA, 'sanctum')->getJson("/api/observations?studentId={$this->student->id}");
        $ownerView->assertOk()->assertJsonCount(1, 'data');

        $otherTeacherView = $this->actingAs($this->teacherB, 'sanctum')->getJson("/api/observations?studentId={$this->student->id}");
        $otherTeacherView->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_non_private_observation_is_visible_to_any_teacher_of_the_student(): void
    {
        StudentObservation::create([
            'student_id' => $this->student->id, 'group_id' => $this->group->id, 'registered_by' => $this->teacherA->id,
            'date' => '2026-02-05', 'type' => 'logro', 'content' => 'Buen desempeño.', 'is_private' => false,
        ]);

        $response = $this->actingAs($this->teacherB, 'sanctum')->getJson("/api/observations?studentId={$this->student->id}");

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertEquals('logro', $response->json('data.0.type'));
    }

    public function test_listing_by_group_id_respects_privacy_and_teacher_access(): void
    {
        StudentObservation::create([
            'student_id' => $this->student->id, 'group_id' => $this->group->id, 'registered_by' => $this->teacherA->id,
            'date' => '2026-02-05', 'type' => 'logro', 'content' => 'Pública.', 'is_private' => false,
        ]);
        StudentObservation::create([
            'student_id' => $this->student->id, 'group_id' => $this->group->id, 'registered_by' => $this->teacherA->id,
            'date' => '2026-02-06', 'type' => 'seguimiento', 'content' => 'Privada de A.', 'is_private' => true,
        ]);

        $ownerView = $this->actingAs($this->teacherA, 'sanctum')->getJson("/api/observations?groupId={$this->group->id}");
        $ownerView->assertOk()->assertJsonCount(2, 'data');

        $otherTeacherView = $this->actingAs($this->teacherB, 'sanctum')->getJson("/api/observations?groupId={$this->group->id}");
        $otherTeacherView->assertOk()->assertJsonCount(1, 'data');
        $this->assertEquals('logro', $otherTeacherView->json('data.0.type'));
    }

    public function test_a_teacher_who_doesnt_teach_any_course_of_the_group_is_forbidden(): void
    {
        $outsider = User::factory()->create();
        InstitutionTeacher::create([
            'institution_id' => $this->institution->id, 'user_id' => $outsider->id, 'role' => 'teacher', 'status' => 'active',
        ]);

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/observations?groupId={$this->group->id}")
            ->assertForbidden();
    }
}
