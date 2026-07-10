<?php

namespace App\Observers;

use App\Models\Grade;
use App\Services\GradeCalculatorService;

class GradeObserver
{
    public function __construct(private GradeCalculatorService $calculator) {}

    public function saved(Grade $grade): void
    {
        $this->recalculate($grade);
    }

    public function deleted(Grade $grade): void
    {
        $this->recalculate($grade);
    }

    private function recalculate(Grade $grade): void
    {
        $this->calculator->recalculateForStudent($grade->student_id, $grade->group_subject_id, $grade->period_id);
    }
}
