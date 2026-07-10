<?php

namespace App\Observers;

use App\Models\AttendanceRecord;
use App\Models\GroupSubject;
use App\Models\Period;
use App\Services\GradeCalculatorService;

class AttendanceObserver
{
    public function __construct(private GradeCalculatorService $calculator) {}

    public function saved(AttendanceRecord $record): void
    {
        $this->recalculate($record);
    }

    public function deleted(AttendanceRecord $record): void
    {
        $this->recalculate($record);
    }

    /**
     * An attendance record only affects the from_attendance columns of the period
     * whose date range contains it, so we resolve that period and reuse the same
     * full cascade used for manual grades.
     */
    private function recalculate(AttendanceRecord $record): void
    {
        $groupSubject = GroupSubject::find($record->group_subject_id);
        if (! $groupSubject) {
            return;
        }

        $period = Period::where('academic_year_id', $groupSubject->academic_year_id)
            ->whereDate('start_date', '<=', $record->date)
            ->whereDate('end_date', '>=', $record->date)
            ->first();

        if (! $period) {
            return;
        }

        $this->calculator->recalculateForStudent($record->student_id, $groupSubject->id, $period->id);
    }
}
