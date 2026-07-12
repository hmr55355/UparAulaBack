<?php

namespace App\Jobs;

use App\Models\Report;
use App\Services\Reports\AcademicRiskReportService;
use App\Services\Reports\AttendanceSheetReportService;
use App\Services\Reports\BehaviorCitationsReportService;
use App\Services\Reports\CopiesSummaryReportService;
use App\Services\Reports\GradeSheetReportService;
use App\Services\Reports\StudentBulletinReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateReportJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $reportId) {}

    public function handle(): void
    {
        $report = Report::findOrFail($this->reportId);
        $report->update(['status' => 'processing']);

        try {
            $filePath = match ($report->type) {
                'grade_sheet' => (new GradeSheetReportService)->generate($report),
                'student_bulletin' => (new StudentBulletinReportService)->generate($report),
                'attendance_sheet' => (new AttendanceSheetReportService)->generate($report),
                'behavior_citations' => (new BehaviorCitationsReportService)->generate($report),
                'academic_risk' => (new AcademicRiskReportService)->generate($report),
                'copies_summary' => (new CopiesSummaryReportService)->generate($report),
            };

            $report->update(['status' => 'completed', 'file_path' => $filePath]);
        } catch (Throwable $e) {
            $report->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
        }
    }
}
