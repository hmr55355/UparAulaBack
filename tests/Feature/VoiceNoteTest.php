<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassPlan;
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

class VoiceNoteTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private ClassPlan $classPlan;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->teacher = User::factory()->create();
        $institution = Institution::create([
            'name' => 'Colegio Test', 'city' => 'Cali', 'department' => 'Valle',
            'min_passing_grade' => 6.0, 'created_by' => $this->teacher->id,
        ]);
        $academicYear = AcademicYear::create([
            'institution_id' => $institution->id, 'year' => 2026,
            'start_date' => '2026-01-20', 'end_date' => '2026-11-28',
        ]);
        $period = Period::create([
            'academic_year_id' => $academicYear->id, 'number' => 1, 'name' => 'Primer Período',
            'start_date' => '2026-01-20', 'end_date' => '2026-03-20', 'is_active' => true,
        ]);
        $group = Group::create([
            'institution_id' => $institution->id, 'academic_year_id' => $academicYear->id,
            'name' => '10-1', 'grade_level' => '10',
        ]);
        $subject = Subject::create(['institution_id' => $institution->id, 'name' => 'Matemáticas']);
        $groupSubject = GroupSubject::create([
            'group_id' => $group->id, 'subject_id' => $subject->id, 'user_id' => $this->teacher->id,
            'institution_id' => $institution->id, 'academic_year_id' => $academicYear->id,
        ]);
        InstitutionTeacher::create([
            'institution_id' => $institution->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'status' => 'active',
        ]);

        $this->classPlan = ClassPlan::create([
            'group_subject_id' => $groupSubject->id, 'period_id' => $period->id,
            'registered_by' => $this->teacher->id, 'date' => '2026-02-05', 'topic' => 'Ángulos',
        ]);
    }

    public function test_a_voice_note_can_be_uploaded_and_downloaded(): void
    {
        $file = UploadedFile::fake()->create('nota.webm', 500, 'audio/webm');

        $response = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/voice-notes', [
            'related_type' => 'class_plan',
            'related_id' => $this->classPlan->id,
            'field_name' => 'pending_for_next_class',
            'audio' => $file,
            'audio_duration_seconds' => 12,
        ]);

        $response->assertCreated();
        $voiceNoteId = $response->json('data.id');
        $path = $response->json('data.audio_file_path');
        Storage::disk('local')->assertExists($path);

        $this->actingAs($this->teacher, 'sanctum')->getJson("/api/voice-notes/{$voiceNoteId}")->assertOk();
    }

    public function test_only_the_author_can_delete_their_voice_note(): void
    {
        $file = UploadedFile::fake()->create('nota.webm', 200, 'audio/webm');
        $voiceNoteId = $this->actingAs($this->teacher, 'sanctum')->postJson('/api/voice-notes', [
            'related_type' => 'class_plan',
            'related_id' => $this->classPlan->id,
            'audio' => $file,
        ])->json('data.id');

        $secondTeacher = User::factory()->create();
        $this->actingAs($secondTeacher, 'sanctum')->deleteJson("/api/voice-notes/{$voiceNoteId}")->assertForbidden();

        $this->actingAs($this->teacher, 'sanctum')->deleteJson("/api/voice-notes/{$voiceNoteId}")->assertOk();
        $this->assertDatabaseCount('voice_notes', 0);
    }

    public function test_a_teacher_who_doesnt_teach_the_course_cannot_upload_a_voice_note_for_its_class_plan(): void
    {
        $outsider = User::factory()->create();
        $file = UploadedFile::fake()->create('nota.webm', 200, 'audio/webm');

        $this->actingAs($outsider, 'sanctum')->postJson('/api/voice-notes', [
            'related_type' => 'class_plan',
            'related_id' => $this->classPlan->id,
            'audio' => $file,
        ])->assertForbidden();
    }
}
