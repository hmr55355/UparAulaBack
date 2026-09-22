<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\GroupSubject;
use App\Models\Period;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Guardado del pase de lista de un día. Lo usan tanto el docente
 * (AttendanceController::bulk) como la aprobación de lo que envía un monitor,
 * para que ambos caminos escriban y recalculen notas exactamente igual.
 */
class AttendanceService
{
    public function __construct(private GradeCalculatorService $calculator) {}

    /**
     * @param  array<int, array{student_id: int, status: string, justification?: string|null}>  $records
     */
    public function saveDay(GroupSubject $groupSubject, string $date, array $records, int $registeredBy): Collection
    {
        // withoutEvents: guardamos todo el pase de lista sin disparar AttendanceObserver
        // por cada fila, y recalculamos una sola vez por estudiante después.
        $saved = DB::transaction(function () use ($groupSubject, $date, $records, $registeredBy) {
            return AttendanceRecord::withoutEvents(function () use ($groupSubject, $date, $records, $registeredBy) {
                // Not updateOrCreate(): its match query compares the raw 'date' string
                // against the stored value, which Eloquent's `date` cast persists with a
                // "00:00:00" time suffix — an exact-string match would never find the
                // existing row and would attempt a duplicate insert on every re-save of
                // the same day. whereDate() correctly ignores that suffix.
                return collect($records)->map(function (array $record) use ($groupSubject, $date, $registeredBy) {
                    $existing = AttendanceRecord::where('student_id', $record['student_id'])
                        ->where('group_subject_id', $groupSubject->id)
                        ->whereDate('date', $date)
                        ->first();

                    $attributes = [
                        'status' => $record['status'],
                        'justification' => $record['justification'] ?? null,
                        'registered_by' => $registeredBy,
                    ];

                    if ($existing) {
                        $existing->update($attributes);

                        return $existing;
                    }

                    return AttendanceRecord::create([
                        'student_id' => $record['student_id'],
                        'group_subject_id' => $groupSubject->id,
                        'date' => $date,
                        ...$attributes,
                    ]);
                });
            });
        });

        $period = $this->periodForDate($groupSubject, $date);
        if ($period) {
            foreach ($saved->pluck('student_id')->unique() as $studentId) {
                $this->calculator->recalculateForStudent($studentId, $groupSubject->id, $period->id);
            }
        }

        return $saved;
    }

    public function periodForDate(GroupSubject $groupSubject, string $date): ?Period
    {
        return Period::where('academic_year_id', $groupSubject->academic_year_id)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();
    }
}
