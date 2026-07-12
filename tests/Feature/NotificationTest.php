<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AppNotification;
use App\Models\BehaviorAnnotation;
use App\Models\ClassSchedule;
use App\Models\GradeColumn;
use App\Models\GradeSection;
use App\Models\Group;
use App\Models\GroupSubject;
use App\Models\Homework;
use App\Models\HomeworkDelivery;
use App\Models\Institution;
use App\Models\InstitutionTeacher;
use App\Models\ParentCitation;
use App\Models\ParentGuardian;
use App\Models\Period;
use App\Models\Student;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Institution $institution;

    private AcademicYear $academicYear;

    private Group $group;

    private GroupSubject $groupSubject;

    private Period $period;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->institution = Institution::create([
            'name' => 'Colegio Test', 'city' => 'Cali', 'department' => 'Valle',
            'min_passing_grade' => 6.0, 'created_by' => $this->teacher->id,
        ]);
        $this->academicYear = AcademicYear::create([
            'institution_id' => $this->institution->id, 'year' => 2026,
            'start_date' => '2026-01-20', 'end_date' => '2026-11-28',
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
        $this->period = Period::create([
            'academic_year_id' => $this->academicYear->id, 'number' => 1, 'name' => 'Primer Período',
            'start_date' => '2026-01-20', 'end_date' => '2026-03-20', 'is_active' => true,
        ]);
        $this->student = Student::create(['institution_id' => $this->institution->id, 'first_name' => 'Ana', 'last_name' => 'Gómez']);
        StudentGroup::create([
            'student_id' => $this->student->id, 'group_id' => $this->group->id, 'academic_year_id' => $this->academicYear->id,
            'enrollment_date' => '2026-01-20', 'status' => 'activo',
        ]);
    }

    public function test_notification_controller_index_mark_read_and_mark_all_read(): void
    {
        $n1 = AppNotification::create([
            'user_id' => $this->teacher->id, 'type' => 'test', 'title' => 'A', 'body' => 'a', 'created_at' => now(),
        ]);
        AppNotification::create([
            'user_id' => $this->teacher->id, 'type' => 'test', 'title' => 'B', 'body' => 'b', 'created_at' => now(),
        ]);

        $response = $this->actingAs($this->teacher, 'sanctum')->getJson('/api/notifications');
        $response->assertOk();
        $this->assertCount(2, $response->json('data'));

        $this->actingAs($this->teacher, 'sanctum')->patchJson("/api/notifications/{$n1->id}/read")->assertOk();
        $this->assertNotNull($n1->fresh()->read_at);

        $this->actingAs($this->teacher, 'sanctum')->patchJson('/api/notifications/read-all')->assertOk();
        $this->assertDatabaseMissing('notifications', ['user_id' => $this->teacher->id, 'read_at' => null]);
    }

    public function test_missing_attendance_command_is_idempotent(): void
    {
        $now = Carbon::now();
        ClassSchedule::create([
            'group_subject_id' => $this->groupSubject->id, 'user_id' => $this->teacher->id,
            'day_of_week' => $now->isoWeekday(), 'start_time' => $now->copy()->subMinutes(30)->format('H:i'),
            'end_time' => $now->copy()->addMinutes(30)->format('H:i'), 'academic_year_id' => $this->academicYear->id,
        ]);

        $this->artisan('notifications:missing-attendance')->assertExitCode(0);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->teacher->id, 'type' => 'missing_attendance']);

        $this->artisan('notifications:missing-attendance')->assertExitCode(0);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_today_citations_command(): void
    {
        $parent = ParentGuardian::create([
            'institution_id' => $this->institution->id, 'first_name' => 'Marta', 'last_name' => 'Gómez',
            'relationship' => 'madre', 'phone' => '3000000000',
        ]);
        ParentCitation::create([
            'student_id' => $this->student->id, 'parent_id' => $parent->id, 'group_id' => $this->group->id,
            'registered_by' => $this->teacher->id, 'citation_type' => 'academica', 'reason' => 'Seguimiento',
            'scheduled_date' => now(), 'status' => 'pendiente',
        ]);

        $this->artisan('notifications:today-citations')->assertExitCode(0);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->teacher->id, 'type' => 'today_citation']);
    }

    public function test_uncontacted_behavior_command(): void
    {
        BehaviorAnnotation::create([
            'student_id' => $this->student->id, 'group_subject_id' => $this->groupSubject->id, 'group_id' => $this->group->id,
            'registered_by' => $this->teacher->id, 'date' => now()->subDays(8), 'type' => 'negativa', 'category' => 'convivencia',
            'title' => 'Falta grave', 'description' => 'Detalle.', 'requires_parent_contact' => true, 'parent_contacted' => false,
        ]);

        $this->artisan('notifications:uncontacted-behavior')->assertExitCode(0);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->teacher->id, 'type' => 'uncontacted_behavior']);
    }

    public function test_closing_periods_command_only_fires_when_a_column_is_incomplete(): void
    {
        $period = Period::create([
            'academic_year_id' => $this->academicYear->id, 'number' => 2, 'name' => 'Segundo Período',
            'start_date' => '2026-03-21', 'end_date' => now()->addDays(7)->toDateString(),
        ]);
        $section = GradeSection::create([
            'group_subject_id' => $this->groupSubject->id, 'period_id' => $period->id,
            'name' => 'Aptitud', 'weight' => 100, 'sort_order' => 0,
        ]);
        GradeColumn::create([
            'grade_section_id' => $section->id, 'group_subject_id' => $this->groupSubject->id, 'period_id' => $period->id,
            'column_type' => 'manual', 'name' => 'Taller', 'weight' => 100, 'max_score' => 10, 'sort_order' => 0,
        ]);

        $this->artisan('notifications:closing-periods')->assertExitCode(0);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->teacher->id, 'type' => 'closing_period']);
    }

    public function test_missing_homework_grades_command(): void
    {
        $homework = Homework::create([
            'group_subject_id' => $this->groupSubject->id, 'period_id' => $this->period->id, 'registered_by' => $this->teacher->id,
            'title' => 'Taller', 'assigned_date' => now()->subDays(10), 'due_date' => now()->subDays(3),
            'is_graded' => true,
        ]);
        HomeworkDelivery::create([
            'homework_id' => $homework->id, 'student_id' => $this->student->id, 'status' => 'entregado',
        ]);

        $this->artisan('notifications:missing-homework-grades')->assertExitCode(0);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->teacher->id, 'type' => 'missing_homework']);
    }
}
