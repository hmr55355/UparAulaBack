<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\BehaviorAnnotation;
use App\Models\Group;
use App\Models\GroupSubject;
use App\Models\Period;
use App\Models\PeriodFinal;
use App\Models\Student;
use App\Models\StudentObservation;
use Illuminate\Support\Collection;

/**
 * Vista del grupo completo para su director (y el administrador): todas las
 * materias de cada estudiante en un período. Es la base del consolidado en
 * pantalla, su exportación y los boletines del grupo.
 */
class GroupOverviewService
{
    /**
     * @return array{
     *   subjects: Collection, students: Collection, rows: array<int, array<string, mixed>>,
     *   subject_stats: array<int, array{average: ?float, failed: int}>, min_passing: float
     * }
     */
    public function consolidated(Group $group, Period $period): array
    {
        $group->loadMissing('institution');
        $minPassing = (float) $group->institution->min_passing_grade;

        $subjects = GroupSubject::where('group_id', $group->id)->where('is_active', true)
            ->with(['subject:id,name,color', 'teacher:id,name'])
            ->get()
            ->sortBy(fn (GroupSubject $gs) => $gs->subject->name)
            ->values();

        $students = Student::whereHas('studentGroups', fn ($q) => $q->where('group_id', $group->id)->where('status', 'activo'))
            ->orderBy('last_name')->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'document_type', 'document_number']);

        $finals = PeriodFinal::whereIn('group_subject_id', $subjects->pluck('id'))
            ->where('period_id', $period->id)
            ->whereIn('student_id', $students->pluck('id'))
            ->get(['student_id', 'group_subject_id', 'period_final'])
            ->groupBy('student_id');

        $absences = AttendanceRecord::whereIn('group_subject_id', $subjects->pluck('id'))
            ->whereIn('student_id', $students->pluck('id'))
            ->where('status', 'ausente_injustificado')
            ->whereDate('date', '>=', $period->start_date)
            ->whereDate('date', '<=', $period->end_date)
            ->selectRaw('student_id, count(*) as total')
            ->groupBy('student_id')
            ->pluck('total', 'student_id');

        $rows = $students->map(function (Student $student) use ($finals, $subjects, $minPassing, $absences) {
            $byCourse = ($finals->get($student->id) ?? collect())->keyBy('group_subject_id');
            $grades = $subjects->mapWithKeys(fn (GroupSubject $gs) => [
                $gs->id => $byCourse->get($gs->id)?->period_final !== null ? (float) $byCourse->get($gs->id)->period_final : null,
            ])->all();
            $scored = array_filter($grades, fn ($g) => $g !== null);

            return [
                'student_id' => $student->id,
                'grades' => $grades,
                'average' => $scored ? round(array_sum($scored) / count($scored), 2) : null,
                'failed' => count(array_filter($scored, fn ($g) => $g < $minPassing)),
                'absences' => (int) ($absences[$student->id] ?? 0),
                'rank' => null,
            ];
        })->all();

        // Puesto por promedio (empates comparten puesto; sin notas, sin puesto).
        $ordered = collect($rows)->filter(fn ($r) => $r['average'] !== null)->sortByDesc('average')->values();
        $rank = 0;
        $previous = null;
        foreach ($ordered as $position => $row) {
            if ($row['average'] !== $previous) {
                $rank = $position + 1;
                $previous = $row['average'];
            }
            $index = array_search($row['student_id'], array_column($rows, 'student_id'), true);
            $rows[$index]['rank'] = $rank;
        }

        $subjectStats = $subjects->mapWithKeys(function (GroupSubject $gs) use ($rows, $minPassing) {
            $values = array_filter(array_map(fn ($r) => $r['grades'][$gs->id], $rows), fn ($g) => $g !== null);

            return [$gs->id => [
                'average' => $values ? round(array_sum($values) / count($values), 2) : null,
                'failed' => count(array_filter($values, fn ($g) => $g < $minPassing)),
            ]];
        })->all();

        return [
            'subjects' => $subjects,
            'students' => $students,
            'rows' => $rows,
            'subject_stats' => $subjectStats,
            'min_passing' => $minPassing,
        ];
    }

    /**
     * Datos del boletín de un estudiante en un período: definitiva de cada materia
     * de su grupo con su nivel y equivalencia nacional, asistencia, comportamiento
     * y observaciones no privadas.
     *
     * @param  array{average: ?float, rank: ?int, failed: int}|null  $summary  fila del consolidado, si se tiene
     */
    public function bulletin(Student $student, ?Group $group, Period $period, Collection $levels, ?array $summary = null, ?int $onlyTeacherId = null): array
    {
        $groupSubjects = $group
            ? GroupSubject::where('group_id', $group->id)->where('is_active', true)
                ->when($onlyTeacherId, fn ($q, $teacherId) => $q->where('user_id', $teacherId))
                ->with('subject')->get()
                ->sortBy(fn (GroupSubject $gs) => $gs->subject->name)->values()
            : collect();

        $finals = PeriodFinal::where('student_id', $student->id)
            ->whereIn('group_subject_id', $groupSubjects->pluck('id'))
            ->where('period_id', $period->id)
            ->get()->keyBy('group_subject_id');

        $grades = $groupSubjects->map(function (GroupSubject $gs) use ($finals, $levels) {
            $value = $finals->get($gs->id)?->period_final !== null ? (float) $finals->get($gs->id)->period_final : null;
            $level = PerformanceScale::levelFor($levels, $value);

            return [
                'subject' => $gs->subject->name,
                'period_final' => $value,
                'level_name' => $level?->name,
                'national_level' => $level ? PerformanceScale::NATIONAL_LABELS[$level->national_level] : null,
                'color' => $level ? PerformanceScale::tint($level->color) : null,
            ];
        });

        return [
            'student' => $student,
            'group' => $group,
            'period' => $period,
            'grades' => $grades,
            'summary' => $summary,
            'attendanceCounts' => AttendanceRecord::where('student_id', $student->id)
                ->whereIn('group_subject_id', $groupSubjects->pluck('id'))
                ->whereDate('date', '>=', $period->start_date)
                ->whereDate('date', '<=', $period->end_date)
                ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'behaviorCounts' => BehaviorAnnotation::where('student_id', $student->id)
                ->whereDate('date', '>=', $period->start_date)
                ->whereDate('date', '<=', $period->end_date)
                ->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type'),
            'observations' => StudentObservation::where('student_id', $student->id)
                ->where('period_id', $period->id)->where('is_private', false)->orderBy('date')->get(),
        ];
    }
}
