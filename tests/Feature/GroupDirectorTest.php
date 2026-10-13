<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\GradeColumn;
use App\Models\GradeLevel;
use App\Models\GradeSection;
use App\Models\Group;
use App\Models\GroupSubject;
use App\Models\InstitutionTeacher;
use App\Models\Period;
use App\Models\Student;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GroupDirectorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $director;

    private User $colleague;

    private int $institutionId;

    private Group $group;

    private Period $period;

    /** @var array<string, GroupSubject> */
    private array $courses = [];

    /** @var Student[] */
    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->admin = User::factory()->create();
        $this->institutionId = $this->actingAs($this->admin, 'sanctum')->postJson('/api/institutions', [
            'name' => 'Colegio', 'city' => 'Valledupar', 'department' => 'Cesar',
            'academic_year' => 2026, 'academic_year_start' => '2026-01-20', 'academic_year_end' => '2026-11-28',
        ])->json('data.id');
        $year = AcademicYear::first();
        $this->period = Period::orderBy('number')->first();

        $decimo = GradeLevel::create(['institution_id' => $this->institutionId, 'name' => 'Décimo', 'level' => 10]);
        GradeLevel::create(['institution_id' => $this->institutionId, 'name' => 'Once', 'level' => 11]);
        $this->group = Group::create([
            'institution_id' => $this->institutionId, 'academic_year_id' => $year->id,
            'name' => '1003', 'grade_level' => '10', 'grade_level_id' => $decimo->id,
        ]);

        [$this->director, $this->colleague] = [User::factory()->create(), User::factory()->create()];
        foreach ([$this->director, $this->colleague] as $teacher) {
            InstitutionTeacher::create(['institution_id' => $this->institutionId, 'user_id' => $teacher->id, 'role' => 'teacher', 'status' => 'active']);
        }

        // El director dicta Matemáticas; el colega, Español. Dos estudiantes con notas.
        foreach (['Matemáticas' => $this->director, 'Español' => $this->colleague] as $name => $teacher) {
            $this->courses[$name] = GroupSubject::create([
                'group_id' => $this->group->id, 'subject_id' => Subject::create(['institution_id' => $this->institutionId, 'name' => $name])->id,
                'user_id' => $teacher->id, 'institution_id' => $this->institutionId, 'academic_year_id' => $year->id,
            ]);
        }
        foreach ([['Ana', 'Díaz'], ['Luis', 'Pérez']] as [$first, $last]) {
            $student = Student::create(['institution_id' => $this->institutionId, 'first_name' => $first, 'last_name' => $last]);
            StudentGroup::create([
                'student_id' => $student->id, 'group_id' => $this->group->id, 'academic_year_id' => $year->id,
                'enrollment_date' => '2026-01-20', 'status' => 'activo',
            ]);
            $this->students[] = $student;
        }
        $scores = ['Matemáticas' => [9.0, 4.0], 'Español' => [7.0, 6.0]];
        foreach ($this->courses as $name => $course) {
            $section = GradeSection::create([
                'group_subject_id' => $course->id, 'period_id' => $this->period->id,
                'name' => 'Notas', 'weight' => 100, 'final_calculation' => 'weighted_avg',
            ]);
            $column = GradeColumn::create([
                'grade_section_id' => $section->id, 'group_subject_id' => $course->id, 'period_id' => $this->period->id,
                'column_type' => 'manual', 'name' => 'Examen', 'weight' => 100,
            ]);
            foreach ($this->students as $i => $student) {
                $this->actingAs($course->teacher, 'sanctum')->postJson('/api/grades', [
                    'student_id' => $student->id, 'grade_column_id' => $column->id, 'score' => $scores[$name][$i],
                ])->assertCreated();
            }
        }
    }

    private function assignDirector(): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/groups/{$this->group->id}", ['director_id' => $this->director->id])
            ->assertOk()->assertJsonPath('data.director.name', $this->director->name);
    }

    public function test_the_director_sees_every_subject_of_the_group_and_a_colleague_does_not(): void
    {
        $url = "/api/groups/{$this->group->id}/consolidated?periodId={$this->period->id}";
        $this->actingAs($this->director, 'sanctum')->getJson($url)->assertForbidden();

        $this->assignDirector();

        $response = $this->actingAs($this->director, 'sanctum')->getJson($url)->assertOk()->assertJsonCount(2, 'subjects');
        $ana = collect($response->json('rows'))->firstWhere('student_id', $this->students[0]->id);
        $luis = collect($response->json('rows'))->firstWhere('student_id', $this->students[1]->id);
        $this->assertEquals([8.0, 1, 0], [$ana['average'], $ana['rank'], $ana['failed']]);
        $this->assertEquals([5.0, 2, 1], [$luis['average'], $luis['rank'], $luis['failed']]);

        $this->actingAs($this->colleague, 'sanctum')->getJson($url)->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->getJson($url)->assertOk();
    }

    public function test_director_downloads_consolidated_bulletins_and_any_teacher_their_grade_sheet(): void
    {
        $this->assignDirector();
        $base = "/api/groups/{$this->group->id}";
        $q = "periodId={$this->period->id}";

        $this->actingAs($this->director, 'sanctum')->get("{$base}/consolidated/export?{$q}&format=excel")->assertOk()->assertDownload();
        $this->actingAs($this->director, 'sanctum')->get("{$base}/consolidated/export?{$q}&format=pdf")->assertOk()->assertDownload();
        $this->actingAs($this->director, 'sanctum')->get("{$base}/bulletins?{$q}")->assertOk()->assertDownload();
        $this->actingAs($this->director, 'sanctum')->get("{$base}/bulletins?{$q}&studentId={$this->students[0]->id}")->assertOk()->assertDownload();
        $this->actingAs($this->colleague, 'sanctum')->getJson("{$base}/bulletins?{$q}")->assertForbidden();

        // La planilla la descarga quien dicta el curso (no hace falta ser director).
        $course = $this->courses['Español'];
        $this->actingAs($this->colleague, 'sanctum')
            ->get("/api/grades/export?groupSubjectId={$course->id}&{$q}&format=excel")->assertOk()->assertDownload();
        $this->actingAs($this->colleague, 'sanctum')
            ->get("/api/grades/export?groupSubjectId={$course->id}&{$q}&format=pdf")->assertOk()->assertDownload();
        $this->actingAs($this->director, 'sanctum')
            ->getJson("/api/grades/export?groupSubjectId={$course->id}&{$q}&format=excel")->assertForbidden();
    }

    public function test_teachers_manage_students_only_when_the_institution_allows_it(): void
    {
        $payload = ['group_id' => $this->group->id, 'withdrawal_date' => '2026-05-01', 'withdrawal_reason' => 'Se retiró'];
        $studentId = $this->students[0]->id;

        $this->actingAs($this->colleague, 'sanctum')->postJson("/api/students/{$studentId}/withdraw", $payload)->assertForbidden();

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/institutions/{$this->institutionId}", ['teachers_manage_students' => true])
            ->assertOk()->assertJsonPath('data.teachers_manage_students', true);

        // Quien dicta en el grupo ya puede; un docente que no dicta en él, no.
        $this->actingAs($this->colleague, 'sanctum')->postJson("/api/students/{$studentId}/withdraw", $payload)->assertOk();
        $this->assertEquals(1, $this->group->fresh()->student_count);

        $outsider = User::factory()->create();
        InstitutionTeacher::create(['institution_id' => $this->institutionId, 'user_id' => $outsider->id, 'role' => 'teacher', 'status' => 'active']);
        $this->actingAs($outsider, 'sanctum')->getJson("/api/groups/{$this->group->id}/enrollments")->assertForbidden();
        $this->actingAs($outsider, 'sanctum')->getJson('/api/groups/mine')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->colleague, 'sanctum')->getJson('/api/groups/mine')->assertJsonPath('data.0.can_manage_students', true);
    }

    public function test_director_and_admin_do_the_pre_enrollment(): void
    {
        $this->assignDirector();
        $url = "/api/groups/{$this->group->id}/pre-enrollments";
        $once = GradeLevel::where('level', 11)->value('id');

        $this->actingAs($this->director, 'sanctum')->getJson($url)->assertOk()
            ->assertJsonPath('target_year', 2027)
            ->assertJsonPath('suggested_grade_level_id', $once)
            ->assertJsonPath('rows.0.status', 'pendiente');

        $this->actingAs($this->director, 'sanctum')->putJson($url, ['target_year' => 2027, 'items' => [
            ['student_id' => $this->students[0]->id, 'status' => 'confirmada', 'target_grade_level_id' => $once],
            ['student_id' => $this->students[1]->id, 'status' => 'no_continua', 'target_grade_level_id' => $once, 'notes' => 'Cambia de ciudad'],
        ]])->assertOk()->assertJsonPath('summary.confirmada', 1)->assertJsonPath('summary.no_continua', 1);

        $this->assertDatabaseHas('pre_enrollments', ['student_id' => $this->students[1]->id, 'status' => 'no_continua', 'target_grade_level_id' => null]);

        $this->actingAs($this->admin, 'sanctum')->get("{$url}/export")->assertOk()->assertDownload();
        $this->actingAs($this->colleague, 'sanctum')->getJson($url)->assertForbidden();
    }
}
