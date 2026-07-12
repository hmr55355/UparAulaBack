<?php

namespace App\Console\Commands\Notifications;

use App\Models\AppNotification;
use App\Models\Grade;
use App\Models\GradeColumn;
use App\Models\GroupSubject;
use App\Models\Period;
use App\Models\StudentGroup;
use Illuminate\Console\Command;

/**
 * Diario: período cierra en 7 días si hay columnas sin calificar (módulo 17).
 */
class NotifyClosingPeriods extends Command
{
    protected $signature = 'notifications:closing-periods';

    protected $description = 'Notifica a los docentes con columnas sin calificar en períodos que cierran en 7 días';

    public function handle(): int
    {
        $targetDate = now()->addDays(7)->toDateString();

        $periods = Period::whereDate('end_date', $targetDate)->get();

        foreach ($periods as $period) {
            $groupSubjects = GroupSubject::where('academic_year_id', $period->academic_year_id)
                ->where('is_active', true)
                ->with('subject', 'group')
                ->get();

            foreach ($groupSubjects as $groupSubject) {
                $activeStudentCount = StudentGroup::where('group_id', $groupSubject->group_id)
                    ->where('status', 'activo')
                    ->count();

                if ($activeStudentCount === 0) {
                    continue;
                }

                $hasIncompleteColumn = GradeColumn::where('group_subject_id', $groupSubject->id)
                    ->where('period_id', $period->id)
                    ->get()
                    ->contains(fn (GradeColumn $column) => Grade::where('grade_column_id', $column->id)
                        ->whereNotNull('score')
                        ->count() < $activeStudentCount
                    );

                if (! $hasIncompleteColumn) {
                    continue;
                }

                AppNotification::firstOrCreate(
                    ['user_id' => $groupSubject->user_id, 'dedupe_key' => "closing_period:{$period->id}:{$groupSubject->id}"],
                    [
                        'type' => 'closing_period',
                        'title' => 'Período por cerrar',
                        'body' => "{$period->name} cierra en 7 días y {$groupSubject->subject->name} — {$groupSubject->group->name} tiene columnas sin calificar.",
                        'data' => ['period_id' => $period->id, 'group_subject_id' => $groupSubject->id],
                        'priority' => 'warning',
                        'created_at' => now(),
                    ]
                );
            }
        }

        return self::SUCCESS;
    }
}
