<?php

use App\Http\Controllers\Api\AcademicYearController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BehaviorAnnotationController;
use App\Http\Controllers\Api\ClassPlanController;
use App\Http\Controllers\Api\ClassScheduleController;
use App\Http\Controllers\Api\CopyChargeController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\GradeColumnController;
use App\Http\Controllers\Api\GradeController;
use App\Http\Controllers\Api\GradeConventionController;
use App\Http\Controllers\Api\GradeExcelController;
use App\Http\Controllers\Api\CourseMonitorController;
use App\Http\Controllers\Api\MonitorController;
use App\Http\Controllers\Api\ParticipationController;
use App\Http\Controllers\Api\GradeLevelController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\GradeSectionController;
use App\Http\Controllers\Api\GradeTemplateController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\GroupSubjectController;
use App\Http\Controllers\Api\HomeworkController;
use App\Http\Controllers\Api\InstitutionController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ParentCitationController;
use App\Http\Controllers\Api\ParentController;
use App\Http\Controllers\Api\PerformanceLevelController;
use App\Http\Controllers\Api\PeriodController;
use App\Http\Controllers\Api\PushSubscriptionController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\StudentImportController;
use App\Http\Controllers\Api\StudentObservationController;
use App\Http\Controllers\Api\SubjectController;
use App\Http\Controllers\Api\VoiceNoteController;
use Illuminate\Support\Facades\Route;

Route::get('/health-check', fn () => response()->json(['status' => 'ok']));

Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

// not.monitor: los monitores de curso solo usan el grupo /monitor de más abajo.
Route::middleware(['auth:sanctum', 'not.monitor'])->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::delete('/me', [AuthController::class, 'destroy']);
        Route::put('/profile', [AuthController::class, 'updateProfile']);
        Route::patch('/notification-preferences', [AuthController::class, 'updateNotificationPreferences']);
    });

    Route::prefix('institutions')->group(function () {
        Route::get('/search', [InstitutionController::class, 'search']);
        Route::get('/current', [InstitutionController::class, 'current']);
        Route::post('/', [InstitutionController::class, 'store']);
        Route::post('/join', [InstitutionController::class, 'join']);
        Route::post('/accept-invitation', [InstitutionController::class, 'acceptInvitation']);
        Route::put('/{institution}', [InstitutionController::class, 'update']);
        Route::get('/{institution}/logo', [InstitutionController::class, 'logo']);
        Route::get('/{institution}/teachers', [InstitutionController::class, 'teachers']);
        Route::post('/{institution}/teachers', [InstitutionController::class, 'createTeacher']);
        Route::post('/{institution}/teachers/invite', [InstitutionController::class, 'inviteTeacher']);
        Route::patch('/{institution}/teachers/{userId}/role', [InstitutionController::class, 'updateTeacherRole']);
        Route::delete('/{institution}/teachers/{userId}', [InstitutionController::class, 'removeTeacher']);
        Route::get('/{institution}/assignment-grid', [InstitutionController::class, 'assignmentGrid']);
        Route::post('/{institution}/assign-course', [InstitutionController::class, 'assignCourse']);
        Route::delete('/{institution}/unassign-course', [InstitutionController::class, 'unassignCourse']);
        Route::get('/{institution}/grade-levels', [GradeLevelController::class, 'index']);
        Route::post('/{institution}/grade-levels', [GradeLevelController::class, 'store']);
        Route::get('/{institution}/shifts', [ShiftController::class, 'index']);
        Route::get('/{institution}/performance-levels', [PerformanceLevelController::class, 'index']);
        Route::put('/{institution}/performance-levels', [PerformanceLevelController::class, 'update']);
        Route::get('/{institution}/performance-levels/suggested', [PerformanceLevelController::class, 'suggested']);
        Route::post('/{institution}/shifts', [ShiftController::class, 'store']);
    });

    Route::put('/grade-levels/{gradeLevel}', [GradeLevelController::class, 'update']);
    Route::delete('/grade-levels/{gradeLevel}', [GradeLevelController::class, 'destroy']);
    Route::put('/shifts/{shift}', [ShiftController::class, 'update']);
    Route::delete('/shifts/{shift}', [ShiftController::class, 'destroy']);
    Route::put('/shifts/{shift}/class-blocks', [ShiftController::class, 'saveBlocks']);
    Route::get('/shifts/{shift}/class-blocks/infer', [ShiftController::class, 'inferBlocks']);

    Route::get('/academic-years', [AcademicYearController::class, 'index']);
    Route::post('/academic-years', [AcademicYearController::class, 'store']);

    Route::get('/periods', [PeriodController::class, 'index']);
    Route::post('/periods', [PeriodController::class, 'store']);
    Route::put('/periods/{period}', [PeriodController::class, 'update']);
    Route::patch('/periods/{period}/set-active', [PeriodController::class, 'setActive']);

    Route::get('/groups', [GroupController::class, 'index']);
    Route::post('/groups', [GroupController::class, 'store']);
    Route::put('/groups/{group}', [GroupController::class, 'update']);
    Route::delete('/groups/{group}', [GroupController::class, 'destroy']);
    Route::get('/groups/{group}/students', [GroupController::class, 'students']);

    Route::get('/subjects', [SubjectController::class, 'index']);
    Route::post('/subjects', [SubjectController::class, 'store']);
    Route::put('/subjects/{subject}', [SubjectController::class, 'update']);

    Route::get('/group-subjects', [GroupSubjectController::class, 'index']);
    Route::post('/group-subjects', [GroupSubjectController::class, 'store']);
    Route::put('/group-subjects/{groupSubject}', [GroupSubjectController::class, 'update']);

    Route::patch('/grade-sections/reorder', [GradeSectionController::class, 'reorder']);
    Route::post('/grade-sections/bulk-save', [GradeSectionController::class, 'bulkSave']);
    Route::post('/grade-sections/copy-from-period', [GradeSectionController::class, 'copyFromPeriod']);
    Route::get('/grade-sections', [GradeSectionController::class, 'index']);
    Route::post('/grade-sections', [GradeSectionController::class, 'store']);
    Route::put('/grade-sections/{gradeSection}', [GradeSectionController::class, 'update']);
    Route::delete('/grade-sections/{gradeSection}', [GradeSectionController::class, 'destroy']);

    Route::patch('/grade-columns/reorder', [GradeColumnController::class, 'reorder']);
    Route::get('/grade-columns', [GradeColumnController::class, 'index']);
    Route::post('/grade-columns', [GradeColumnController::class, 'store']);
    Route::put('/grade-columns/{gradeColumn}', [GradeColumnController::class, 'update']);
    Route::delete('/grade-columns/{gradeColumn}', [GradeColumnController::class, 'destroy']);

    Route::get('/grade-templates', [GradeTemplateController::class, 'index']);
    Route::post('/grade-templates', [GradeTemplateController::class, 'store']);
    Route::put('/grade-templates/{gradeTemplate}', [GradeTemplateController::class, 'update']);
    Route::delete('/grade-templates/{gradeTemplate}', [GradeTemplateController::class, 'destroy']);
    Route::post('/grade-templates/{gradeTemplate}/apply', [GradeTemplateController::class, 'apply']);

    Route::get('/grade-conventions', [GradeConventionController::class, 'index']);
    Route::post('/grade-conventions', [GradeConventionController::class, 'store']);
    Route::post('/grade-conventions/suggested', [GradeConventionController::class, 'storeSuggested']);
    Route::patch('/grade-conventions/reorder', [GradeConventionController::class, 'reorder']);
    Route::put('/grade-conventions/{gradeConvention}', [GradeConventionController::class, 'update']);
    Route::delete('/grade-conventions/{gradeConvention}', [GradeConventionController::class, 'destroy']);

    Route::post('/grades/bulk', [GradeController::class, 'bulk']);
    Route::get('/grades/excel-template', [GradeExcelController::class, 'template']);
    Route::post('/grades/excel-import', [GradeExcelController::class, 'import']);
    Route::get('/grades/student/{studentId}', [GradeController::class, 'studentGrades']);
    Route::get('/grades', [GradeController::class, 'index']);
    Route::post('/grades', [GradeController::class, 'store']);
    Route::put('/grades/{grade}', [GradeController::class, 'update']);

    Route::post('/section-finals/calculate', [GradeController::class, 'calculateSectionFinals']);
    Route::get('/section-finals', [GradeController::class, 'sectionFinals']);
    Route::put('/section-finals/{sectionFinal}/adjust', [GradeController::class, 'adjustSectionFinal']);

    Route::post('/period-finals/calculate', [GradeController::class, 'calculatePeriodFinals']);
    Route::get('/period-finals', [GradeController::class, 'periodFinals']);
    Route::put('/period-finals/{periodFinal}/adjust', [GradeController::class, 'adjustPeriodFinal']);

    Route::post('/schedule/duplicate-day', [ClassScheduleController::class, 'duplicateDay']);
    Route::get('/schedule/current-class', [ClassScheduleController::class, 'currentClass']);
    Route::get('/schedule/today', [ClassScheduleController::class, 'today']);
    Route::get('/schedule', [ClassScheduleController::class, 'index']);
    Route::post('/schedule', [ClassScheduleController::class, 'store']);
    Route::put('/schedule/{classSchedule}', [ClassScheduleController::class, 'update']);
    Route::delete('/schedule/{classSchedule}', [ClassScheduleController::class, 'destroy']);

    Route::post('/attendance/bulk', [AttendanceController::class, 'bulk']);
    Route::get('/attendance/stats', [AttendanceController::class, 'stats']);
    Route::get('/attendance/sheet', [AttendanceController::class, 'sheet']);
    Route::get('/attendance', [AttendanceController::class, 'index']);
    Route::put('/attendance/{attendanceRecord}', [AttendanceController::class, 'update']);

    Route::get('/behavior', [BehaviorAnnotationController::class, 'index']);
    Route::post('/behavior', [BehaviorAnnotationController::class, 'store']);
    Route::get('/behavior/{behaviorAnnotation}', [BehaviorAnnotationController::class, 'show']);
    Route::put('/behavior/{behaviorAnnotation}', [BehaviorAnnotationController::class, 'update']);
    Route::delete('/behavior/{behaviorAnnotation}', [BehaviorAnnotationController::class, 'destroy']);
    Route::patch('/behavior/{behaviorAnnotation}/mark-contacted', [BehaviorAnnotationController::class, 'markContacted']);

    Route::get('/citations', [ParentCitationController::class, 'index']);
    Route::post('/citations', [ParentCitationController::class, 'store']);
    Route::get('/citations/{citation}', [ParentCitationController::class, 'show']);
    Route::put('/citations/{citation}', [ParentCitationController::class, 'update']);
    Route::patch('/citations/{citation}/status', [ParentCitationController::class, 'updateStatus']);
    Route::delete('/citations/{citation}', [ParentCitationController::class, 'destroy']);

    Route::get('/parents', [ParentController::class, 'index']);
    Route::post('/parents', [ParentController::class, 'store']);
    Route::put('/parents/{parent}', [ParentController::class, 'update']);

    Route::get('/observations', [StudentObservationController::class, 'index']);
    Route::post('/observations', [StudentObservationController::class, 'store']);
    Route::put('/observations/{studentObservation}', [StudentObservationController::class, 'update']);
    Route::delete('/observations/{studentObservation}', [StudentObservationController::class, 'destroy']);

    Route::get('/students/{student}/full-profile', [StudentController::class, 'fullProfile']);

    Route::get('/homeworks', [HomeworkController::class, 'index']);
    Route::post('/homeworks', [HomeworkController::class, 'store']);
    Route::put('/homeworks/{homework}', [HomeworkController::class, 'update']);
    Route::delete('/homeworks/{homework}', [HomeworkController::class, 'destroy']);
    Route::get('/homeworks/{homework}/deliveries', [HomeworkController::class, 'deliveries']);
    Route::post('/homeworks/{homework}/deliveries/bulk', [HomeworkController::class, 'bulkDeliveries']);

    Route::get('/class-plans/previous', [ClassPlanController::class, 'previous']);
    Route::get('/class-plans', [ClassPlanController::class, 'index']);
    Route::post('/class-plans', [ClassPlanController::class, 'store']);
    Route::put('/class-plans/{classPlan}', [ClassPlanController::class, 'update']);
    Route::patch('/class-plans/{classPlan}/status', [ClassPlanController::class, 'updateStatus']);
    Route::post('/class-plans/{classPlan}/duplicate-as-base', [ClassPlanController::class, 'duplicateAsBase']);

    Route::get('/voice-notes', [VoiceNoteController::class, 'index']);
    Route::post('/voice-notes', [VoiceNoteController::class, 'store']);
    Route::get('/voice-notes/{voiceNote}', [VoiceNoteController::class, 'show']);
    Route::delete('/voice-notes/{voiceNote}', [VoiceNoteController::class, 'destroy']);

    Route::get('/copy-charges', [CopyChargeController::class, 'index']);
    Route::post('/copy-charges', [CopyChargeController::class, 'store']);
    Route::patch('/copy-charges/payments/{payment}', [CopyChargeController::class, 'updatePaymentSingle']);
    Route::put('/copy-charges/{copyCharge}', [CopyChargeController::class, 'update']);
    Route::delete('/copy-charges/{copyCharge}', [CopyChargeController::class, 'destroy']);
    Route::get('/copy-charges/{copyCharge}/payments', [CopyChargeController::class, 'payments']);
    Route::post('/copy-charges/{copyCharge}/payments/bulk', [CopyChargeController::class, 'bulkPayments']);
    Route::post('/copy-charges/summary', [CopyChargeController::class, 'summary']);

    Route::post('/reports/grade-sheet', [ReportController::class, 'gradeSheet']);
    Route::post('/reports/student-bulletin', [ReportController::class, 'studentBulletin']);
    Route::post('/reports/attendance-sheet', [ReportController::class, 'attendanceSheet']);
    Route::post('/reports/behavior-citations', [ReportController::class, 'behaviorCitations']);
    Route::post('/reports/academic-risk', [ReportController::class, 'academicRisk']);
    Route::get('/reports/{report}', [ReportController::class, 'show']);
    Route::get('/reports/{report}/download', [ReportController::class, 'download']);

    // Monitores de curso (lado del docente) y participaciones.
    Route::get('/group-subjects/{groupSubject}/monitors', [CourseMonitorController::class, 'index']);
    Route::post('/group-subjects/{groupSubject}/monitors', [CourseMonitorController::class, 'store']);
    Route::patch('/course-monitors/{courseMonitor}', [CourseMonitorController::class, 'update']);
    Route::get('/monitor-submissions', [CourseMonitorController::class, 'submissions']);
    Route::get('/monitor-submissions/{submission}', [CourseMonitorController::class, 'showSubmission']);
    Route::post('/monitor-submissions/{submission}/approve', [CourseMonitorController::class, 'approve']);
    Route::post('/monitor-submissions/{submission}/reject', [CourseMonitorController::class, 'reject']);
    Route::get('/participations', [ParticipationController::class, 'index']);
    Route::post('/participations', [ParticipationController::class, 'store']);
    Route::delete('/participations/{participation}', [ParticipationController::class, 'destroy']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead']);

    Route::get('/push/vapid-public-key', [PushSubscriptionController::class, 'vapidPublicKey']);
    Route::post('/push-subscriptions', [PushSubscriptionController::class, 'store']);
    Route::delete('/push-subscriptions', [PushSubscriptionController::class, 'destroy']);

    Route::post('/students/import', [StudentImportController::class, 'store']);
    Route::get('/academic-years/{academicYear}/export', [ExportController::class, 'yearData']);
});

// Cuenta de monitor de curso: solo esto (más cerrar sesión / ver su usuario).
Route::middleware(['auth:sanctum', 'monitor'])->prefix('monitor')->group(function () {
    Route::get('/courses', [MonitorController::class, 'courses']);
    Route::get('/courses/{courseMonitor}/roster', [MonitorController::class, 'roster']);
    Route::post('/courses/{courseMonitor}/submissions', [MonitorController::class, 'submit']);
    Route::get('/submissions', [MonitorController::class, 'submissions']);
    Route::delete('/submissions/{submission}', [MonitorController::class, 'cancel']);
});
