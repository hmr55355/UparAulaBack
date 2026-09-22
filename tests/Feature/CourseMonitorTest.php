<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AppNotification;
use App\Models\GradeColumn;
use App\Models\GradeSection;
use App\Models\Group;
use App\Models\GroupSubject;
use App\Models\Institution;
use App\Models\InstitutionTeacher;
use App\Models\MonitorSubmission;
use App\Models\Period;
use App\Models\Student;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseMonitorTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private GroupSubject $groupSubject;

    private Period $period;

    /** @var Student[] */
    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $institution = Institution::create([
            'name' => 'Colegio Test', 'city' => 'Cali', 'department' => 'Valle',
            'min_passing_grade' => 6.0, 'created_by' => $this->teacher->id,
        ]);
        $year = AcademicYear::create([
            'institution_id' => $institution->id, 'year' => 2026, 'start_date' => '2026-01-20', 'end_date' => '2026-11-28',
        ]);
        $this->period = Period::create([
            'academic_year_id' => $year->id, 'number' => 1, 'name' => 'Primer Período',
            'start_date' => '2026-01-20', 'end_date' => '2026-03-20', 'is_active' => true,
        ]);
        $group = Group::create(['institution_id' => $institution->id, 'academic_year_id' => $year->id, 'name' => '1001', 'grade_level' => '10']);
        $subject = Subject::create(['institution_id' => $institution->id, 'name' => 'Trigonometría']);
        $this->groupSubject = GroupSubject::create([
            'group_id' => $group->id, 'subject_id' => $subject->id, 'user_id' => $this->teacher->id,
            'institution_id' => $institution->id, 'academic_year_id' => $year->id,
        ]);
        InstitutionTeacher::create(['institution_id' => $institution->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'status' => 'active']);

        foreach (['Ana', 'Luis', 'Sara'] as $name) {
            $student = Student::create(['institution_id' => $institution->id, 'first_name' => $name, 'last_name' => 'Pérez']);
            StudentGroup::create(['student_id' => $student->id, 'group_id' => $group->id, 'academic_year_id' => $year->id, 'enrollment_date' => '2026-01-20', 'status' => 'activo']);
            $this->students[] = $student;
        }
    }

    /** Crea el monitor desde la cuenta del docente y devuelve [courseMonitorId, token del monitor]. */
    private function createMonitorAndLogin(): array
    {
        $monitorId = $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/group-subjects/{$this->groupSubject->id}/monitors", [
                'student_id' => $this->students[0]->id, 'username' => 'AnaMonitor', 'password' => 'secreto1',
            ])->assertCreated()->json('data.id');

        $token = $this->postJson('/api/auth/login', ['email' => 'anamonitor', 'password' => 'secreto1'])
            ->assertOk()->json('token');
        $this->app['auth']->forgetGuards();

        return [$monitorId, $token];
    }

    private function asMonitor(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_monitor_logs_in_with_username_and_is_kept_out_of_the_teacher_api(): void
    {
        [, $token] = $this->createMonitorAndLogin();

        $this->asMonitor($token)->getJson('/api/monitor/courses')->assertOk()
            ->assertJsonPath('data.0.group_name', '1001');
        $this->asMonitor($token)->getJson('/api/group-subjects')->assertForbidden();
        $this->asMonitor($token)->getJson("/api/grades?groupSubjectId={$this->groupSubject->id}&periodId={$this->period->id}")->assertForbidden();
        $this->asMonitor($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.account_type', 'monitor');
    }

    public function test_attendance_from_a_monitor_only_counts_after_the_teacher_approves_it(): void
    {
        $section = GradeSection::create(['group_subject_id' => $this->groupSubject->id, 'period_id' => $this->period->id, 'name' => 'Aptitud', 'weight' => 100]);
        $asist = GradeColumn::create([
            'grade_section_id' => $section->id, 'group_subject_id' => $this->groupSubject->id, 'period_id' => $this->period->id,
            'column_type' => 'from_attendance', 'name' => 'Asistencia', 'weight' => 100,
        ]);
        [$monitorId, $token] = $this->createMonitorAndLogin();

        $payload = ['type' => 'attendance', 'payload' => ['date' => '2026-02-02', 'records' => [
            ['student_id' => $this->students[1]->id, 'status' => 'presente'],
        ]]];
        $this->asMonitor($token)->postJson("/api/monitor/courses/{$monitorId}/submissions", $payload)->assertCreated();
        // Corregir el mismo día mientras sigue pendiente edita el envío, no crea otro.
        $payload['payload']['records'][0]['status'] = 'ausente_injustificado';
        $this->asMonitor($token)->postJson("/api/monitor/courses/{$monitorId}/submissions", $payload)->assertCreated();

        $this->assertSame(1, MonitorSubmission::count());
        $this->assertDatabaseCount('attendance_records', 0);

        $notification = AppNotification::where('user_id', $this->teacher->id)->where('type', 'monitor_submission')->first();
        $submissionId = MonitorSubmission::first()->id;
        $this->assertSame("/monitors/reviews/{$submissionId}", $notification->data['url']);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->teacher, 'sanctum')->postJson("/api/monitor-submissions/{$submissionId}/approve")->assertOk();

        $this->assertDatabaseHas('attendance_records', ['student_id' => $this->students[1]->id, 'status' => 'ausente_injustificado']);
        $this->assertDatabaseHas('grades', ['student_id' => $this->students[1]->id, 'grade_column_id' => $asist->id, 'score' => 9.5]);
        $this->assertSame('approved', MonitorSubmission::first()->status);
    }

    public function test_participation_grade_uses_the_top_participant_as_the_maximum(): void
    {
        $section = GradeSection::create(['group_subject_id' => $this->groupSubject->id, 'period_id' => $this->period->id, 'name' => 'Aptitud', 'weight' => 100]);
        $column = GradeColumn::create([
            'grade_section_id' => $section->id, 'group_subject_id' => $this->groupSubject->id, 'period_id' => $this->period->id,
            'column_type' => 'from_participation', 'name' => 'Participación', 'weight' => 100,
        ]);
        [$monitorId, $token] = $this->createMonitorAndLogin();

        $submissionId = $this->asMonitor($token)->postJson("/api/monitor/courses/{$monitorId}/submissions", [
            'type' => 'participation',
            'payload' => ['date' => '2026-02-03', 'entries' => [
                ['student_id' => $this->students[0]->id, 'points' => 3],
                ['student_id' => $this->students[1]->id, 'points' => 1],
            ]],
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseCount('participations', 0);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->teacher, 'sanctum')->postJson("/api/monitor-submissions/{$submissionId}/approve")->assertOk();

        $score = fn (Student $s) => (float) \App\Models\Grade::where('student_id', $s->id)->where('grade_column_id', $column->id)->value('score');
        $this->assertSame(10.0, $score($this->students[0]));
        $this->assertSame(3.3, $score($this->students[1]));
        $this->assertSame(1.0, $score($this->students[2]));
    }

    public function test_rejected_behavior_is_never_written_and_approved_behavior_is(): void
    {
        [$monitorId, $token] = $this->createMonitorAndLogin();
        $submit = fn (string $type) => $this->asMonitor($token)->postJson("/api/monitor/courses/{$monitorId}/submissions", [
            'type' => 'behavior',
            'payload' => ['date' => '2026-02-04', 'student_id' => $this->students[2]->id, 'type' => $type, 'observation' => 'Ayudó a un compañero'],
        ])->assertCreated()->json('data.id');

        $rejected = $submit('negativa');
        $approved = $submit('positiva');

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->teacher, 'sanctum')->postJson("/api/monitor-submissions/{$rejected}/reject", ['notes' => 'No fue así'])->assertOk();
        $this->actingAs($this->teacher, 'sanctum')->postJson("/api/monitor-submissions/{$approved}/approve")->assertOk();
        $this->actingAs($this->teacher, 'sanctum')->postJson("/api/monitor-submissions/{$approved}/approve")->assertStatus(422);

        $this->assertDatabaseCount('behavior_annotations', 1);
        $this->assertDatabaseHas('behavior_annotations', ['student_id' => $this->students[2]->id, 'type' => 'positiva']);
    }

    public function test_a_deactivated_monitor_can_no_longer_log_in(): void
    {
        [$monitorId] = $this->createMonitorAndLogin();

        $this->actingAs($this->teacher, 'sanctum')->patchJson("/api/course-monitors/{$monitorId}", ['is_active' => false])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/auth/login', ['email' => 'anamonitor', 'password' => 'secreto1'])->assertStatus(422);
    }

    public function test_monitor_cannot_submit_students_from_another_group(): void
    {
        [$monitorId, $token] = $this->createMonitorAndLogin();
        $outsider = Student::create(['institution_id' => $this->groupSubject->institution_id, 'first_name' => 'Otro', 'last_name' => 'Grupo']);

        $this->asMonitor($token)->postJson("/api/monitor/courses/{$monitorId}/submissions", [
            'type' => 'behavior',
            'payload' => ['date' => '2026-02-04', 'student_id' => $outsider->id, 'type' => 'positiva', 'observation' => 'x'],
        ])->assertStatus(422);
    }
}
