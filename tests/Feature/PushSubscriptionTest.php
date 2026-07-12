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
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = User::factory()->create();
    }

    public function test_vapid_public_key_endpoint(): void
    {
        config(['services.vapid.public_key' => 'test-public-key']);

        $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/push/vapid-public-key')
            ->assertOk()
            ->assertJson(['publicKey' => 'test-public-key']);
    }

    public function test_subscribe_then_unsubscribe(): void
    {
        $payload = [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
            'keys' => ['p256dh' => 'p256dh-value', 'auth' => 'auth-value'],
        ];

        $this->actingAs($this->teacher, 'sanctum')->postJson('/api/push-subscriptions', $payload)->assertCreated();
        $this->assertDatabaseHas('push_subscriptions', ['user_id' => $this->teacher->id, 'endpoint' => $payload['endpoint']]);

        // Re-subscribing with the same endpoint updates instead of duplicating.
        $this->actingAs($this->teacher, 'sanctum')->postJson('/api/push-subscriptions', $payload)->assertCreated();
        $this->assertDatabaseCount('push_subscriptions', 1);

        $this->actingAs($this->teacher, 'sanctum')
            ->deleteJson('/api/push-subscriptions', ['endpoint' => $payload['endpoint']])
            ->assertOk();
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_upcoming_class_push_command_sends_once_and_is_idempotent(): void
    {
        $this->mock(WebPushService::class, function ($mock) {
            $mock->shouldReceive('sendToUser')->once();
        });

        $institution = Institution::create([
            'name' => 'Colegio Test', 'city' => 'Cali', 'department' => 'Valle',
            'min_passing_grade' => 6.0, 'created_by' => $this->teacher->id,
        ]);
        $academicYear = AcademicYear::create([
            'institution_id' => $institution->id, 'year' => 2026,
            'start_date' => '2026-01-20', 'end_date' => '2026-11-28',
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

        $now = Carbon::now();
        ClassSchedule::create([
            'group_subject_id' => $groupSubject->id, 'user_id' => $this->teacher->id,
            'day_of_week' => $now->isoWeekday(), 'start_time' => $now->copy()->addSeconds(270)->format('H:i:s'),
            'end_time' => $now->copy()->addMinutes(34)->format('H:i'), 'classroom' => 'Salón 3',
            'academic_year_id' => $academicYear->id,
        ]);

        $this->artisan('notifications:upcoming-class-push')->assertExitCode(0);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->teacher->id, 'type' => 'upcoming_class_push']);

        // Running again in the same window must not send a second push (mock expects ->once()).
        $this->artisan('notifications:upcoming-class-push')->assertExitCode(0);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_upcoming_class_push_respects_the_teachers_preference_toggle(): void
    {
        $this->mock(WebPushService::class, function ($mock) {
            $mock->shouldNotReceive('sendToUser');
        });

        $this->teacher->update(['notification_preferences' => ['upcoming_class_push' => false]]);

        $institution = Institution::create([
            'name' => 'Colegio Test', 'city' => 'Cali', 'department' => 'Valle',
            'min_passing_grade' => 6.0, 'created_by' => $this->teacher->id,
        ]);
        $academicYear = AcademicYear::create([
            'institution_id' => $institution->id, 'year' => 2026,
            'start_date' => '2026-01-20', 'end_date' => '2026-11-28',
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

        $now = Carbon::now();
        ClassSchedule::create([
            'group_subject_id' => $groupSubject->id, 'user_id' => $this->teacher->id,
            'day_of_week' => $now->isoWeekday(), 'start_time' => $now->copy()->addSeconds(270)->format('H:i:s'),
            'end_time' => $now->copy()->addMinutes(34)->format('H:i'), 'academic_year_id' => $academicYear->id,
        ]);

        $this->artisan('notifications:upcoming-class-push')->assertExitCode(0);
        $this->assertDatabaseCount('notifications', 0);
    }
}
