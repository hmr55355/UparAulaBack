<?php

namespace App\Services\Reports;

use App\Models\AttendanceRecord;
use App\Models\BehaviorAnnotation;
use App\Models\GroupSubject;
use App\Models\Period;
use App\Models\PeriodFinal;
use App\Models\Report;
use App\Models\Student;
use App\Models\StudentGroup;
use App\Models\StudentObservation;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Boletín individual del estudiante (módulo 16.B) — a solo-PDF administrative
 * document, so unlike the other report types it lists grades from EVERY
 * subject of the student's active group, not just the group_subjects the
 * requesting teacher happens to teach (that narrower filter is what
 * StudentController::fullProfile uses for the in-app profile tab instead).
 */
class StudentBulletinReportService
{
    public function generate(Report $report): string
    {
        $params = $report->params;
        $student = Student::with('institution')->findOrFail($params['student_id']);
        $period = Period::findOrFail($params['period_id']);

        $studentGroup = StudentGroup::where('student_id', $student->id)
            ->where('academic_year_id', $period->academic_year_id)
            ->where('status', 'activo')
            ->with('group')
            ->first();

        $groupSubjects = $studentGroup
            ? GroupSubject::where('group_id', $studentGroup->group_id)->where('is_active', true)->with('subject')->get()
            : collect();

        $grades = $groupSubjects->map(function (GroupSubject $gs) use ($student, $period) {
            $periodFinal = PeriodFinal::where('student_id', $student->id)
                ->where('group_subject_id', $gs->id)
                ->where('period_id', $period->id)
                ->first();

            return [
                'subject' => $gs->subject->name,
                'period_final' => $periodFinal->period_final ?? null,
                'is_promoted' => $periodFinal->is_promoted ?? null,
            ];
        });

        $attendanceCounts = AttendanceRecord::where('student_id', $student->id)
            ->whereIn('group_subject_id', $groupSubjects->pluck('id'))
            ->whereBetween('date', [$period->start_date, $period->end_date])
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $behaviorCounts = BehaviorAnnotation::where('student_id', $student->id)
            ->whereBetween('date', [$period->start_date, $period->end_date])
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $observations = StudentObservation::where('student_id', $student->id)
            ->where('period_id', $period->id)
            ->where('is_private', false)
            ->orderBy('date')
            ->get();

        $relativePath = "reports/{$student->institution_id}/{$report->user_id}/".Str::uuid().'.pdf';

        PdfTableRenderer::render($relativePath, 'reports.student-bulletin', [
            'student' => $student,
            'logoPath' => $student->institution->logo ? Storage::disk('local')->path($student->institution->logo) : null,
            'period' => $period,
            'group' => $studentGroup?->group,
            'grades' => $grades,
            'attendanceCounts' => $attendanceCounts,
            'behaviorCounts' => $behaviorCounts,
            'observations' => $observations,
        ]);

        return $relativePath;
    }
}
