<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassSchedule;
use App\Models\Group;
use App\Models\GroupSubject;
use App\Models\Institution;
use App\Models\InstitutionTeacher;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ClassScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private AcademicYear $academicYear;

    private GroupSubject $groupSubject;

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
        $this->academicYear = AcademicYear::create([
            'institution_id' => $institution->id, 'year' => 2026,
            'start_date' => '2026-01-20', 'end_date' => '2026-11-28',
        ]);
        $group = Group::create([
            'institution_id' => $institution->id, 'academic_year_id' => $this->academicYear->id,
            'name' => '10-1', 'grade_level' => '10',
        ]);
        $subject = Subject::create(['institution_id' => $institution->id, 'name' => 'Matemáticas']);
        $this->groupSubject = GroupSubject::create([
            'group_id' => $group->id, 'subject_id' => $subject->id, 'user_id' => $this->teacher->id,
            'institution_id' => $institution->id, 'academic_year_id' => $this->academicYear->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_detects_a_class_in_progress(): void
    {
        $now = Carbon::create(2026, 2, 2, 7, 30, 0);
        Carbon::setTestNow($now);

        ClassSchedule::create([
            'group_subject_id' => $this->groupSubject->id, 'user_id' => $this->teacher->id,
            'day_of_week' => $now->isoWeekday(), 'start_time' => '07:00:00', 'end_time' => '07:50:00',
            'academic_year_id' => $this->academicYear->id,
        ]);

        $response = $this->actingAs($this->teacher, 'sanctum')->getJson('/api/schedule/current-class');

        $response->assertOk()->assertJsonPath('status', 'en_curso');
    }

    public function test_detects_an_upcoming_class_within_15_minutes(): void
    {
        $now = Carbon::create(2026, 2, 2, 7, 50, 0);
        Carbon::setTestNow($now);

        ClassSchedule::create([
            'group_subject_id' => $this->groupSubject->id, 'user_id' => $this->teacher->id,
            'day_of_week' => $now->isoWeekday(), 'start_time' => '08:00:00', 'end_time' => '08:50:00',
            'academic_year_id' => $this->academicYear->id,
        ]);

        $response = $this->actingAs($this->teacher, 'sanctum')->getJson('/api/schedule/current-class');

        $response->assertOk()->assertJsonPath('status', 'proxima')->assertJsonPath('minutes_until', 10);
    }

    public function test_reports_no_class_when_nothing_is_close(): void
    {
        $now = Carbon::create(2026, 2, 2, 7, 0, 0);
        Carbon::setTestNow($now);

        ClassSchedule::create([
            'group_subject_id' => $this->groupSubject->id, 'user_id' => $this->teacher->id,
            'day_of_week' => $now->isoWeekday(), 'start_time' => '10:00:00', 'end_time' => '10:50:00',
            'academic_year_id' => $this->academicYear->id,
        ]);

        $response = $this->actingAs($this->teacher, 'sanctum')->getJson('/api/schedule/current-class');

        $response->assertOk()->assertJsonPath('status', 'sin_clase_ahora');
        $this->assertNotNull($response->json('block'));
    }

    public function test_creating_an_overlapping_block_for_the_same_teacher_requires_confirmation(): void
    {
        ClassSchedule::create([
            'group_subject_id' => $this->groupSubject->id, 'user_id' => $this->teacher->id,
            'day_of_week' => 1, 'start_time' => '07:00:00', 'end_time' => '07:50:00',
            'academic_year_id' => $this->academicYear->id,
        ]);

        $payload = [
            'group_subject_id' => $this->groupSubject->id,
            'day_of_week' => 1, 'start_time' => '07:30', 'end_time' => '08:20',
            'academic_year_id' => $this->academicYear->id,
        ];

        $response = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/schedule', $payload);
        $response->assertStatus(409);
        $this->assertDatabaseCount('class_schedules', 1);

        $confirmed = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/schedule', [...$payload, 'confirm' => true]);
        $confirmed->assertCreated();
        $this->assertDatabaseCount('class_schedules', 2);
    }

    public function test_classroom_overlap_with_another_teacher_never_blocks_but_warns(): void
    {
        $otherTeacher = User::factory()->create();
        InstitutionTeacher::create([
            'institution_id' => $this->groupSubject->institution_id, 'user_id' => $otherTeacher->id,
            'role' => 'teacher', 'status' => 'active',
        ]);
        $otherSubject = Subject::create(['institution_id' => $this->groupSubject->institution_id, 'name' => 'Trigonometría']);
        $otherGroupSubject = GroupSubject::create([
            'group_id' => $this->groupSubject->group_id, 'subject_id' => $otherSubject->id,
            'user_id' => $otherTeacher->id, 'institution_id' => $this->groupSubject->institution_id,
            'academic_year_id' => $this->academicYear->id,
        ]);
        ClassSchedule::create([
            'group_subject_id' => $otherGroupSubject->id, 'user_id' => $otherTeacher->id,
            'day_of_week' => 2, 'start_time' => '09:00:00', 'end_time' => '09:50:00',
            'classroom' => 'Salón 10-1', 'academic_year_id' => $this->academicYear->id,
        ]);

        $response = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/schedule', [
            'group_subject_id' => $this->groupSubject->id,
            'day_of_week' => 2, 'start_time' => '09:20', 'end_time' => '10:10',
            'classroom' => 'Salón 10-1', 'academic_year_id' => $this->academicYear->id,
        ]);

        $response->assertCreated();
        $this->assertNotEmpty($response->json('warnings'));
        $this->assertDatabaseCount('class_schedules', 2);
    }

    public function test_duplicate_day_copies_blocks_to_another_day(): void
    {
        ClassSchedule::create([
            'group_subject_id' => $this->groupSubject->id, 'user_id' => $this->teacher->id,
            'day_of_week' => 1, 'start_time' => '07:00:00', 'end_time' => '07:50:00',
            'classroom' => 'Salón 10-1', 'academic_year_id' => $this->academicYear->id,
        ]);

        $response = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/schedule/duplicate-day', [
            'from_day' => 1,
            'to_day' => 3,
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('class_schedules', 2);
        $this->assertDatabaseHas('class_schedules', ['day_of_week' => 3, 'start_time' => '07:00:00']);
    }
}
