<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\BehaviorAnnotation;
use App\Models\GroupSubject;
use App\Models\ParentCitation;
use App\Models\Period;
use App\Models\PeriodFinal;
use App\Models\Student;
use App\Models\StudentCopyPayment;
use App\Models\StudentObservation;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class StudentController extends Controller
{
    /**
     * Aggregates everything the requesting teacher is allowed to see about a
     * student into the tabs described in "PERFIL DEL ESTUDIANTE" (módulo 12):
     * notas, asistencia, comportamiento, observaciones, acudientes.
     */
    public function fullProfile(Request $request, Student $student)
    {
        abort_unless($student->canBeAccessedBy($request->user()), 403);

        $activeGroupIds = $student->studentGroups()->where('status', 'activo')->pluck('group_id');

        // Solo las materias que el docente que consulta realmente dicta a este
        // estudiante — nunca las de un colega (nota del prompt: "notas del período
        // actual en todas las materias del docente que accede").
        $groupSubjects = GroupSubject::whereIn('group_id', $activeGroupIds)
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->with('subject:id,name,color', 'group:id,name')
            ->get();

        $activePeriod = $groupSubjects->isNotEmpty()
            ? Period::where('academic_year_id', $groupSubjects->first()->academic_year_id)->where('is_active', true)->first()
            : null;

        [$grades, $attendanceSummary] = $this->buildGradesAndAttendance($student, $groupSubjects, $activePeriod);

        $behavior = BehaviorAnnotation::where('student_id', $student->id)
            ->with(['registeredBy:id,name'])
            ->orderByDesc('date')
            ->get()
            ->map(fn (BehaviorAnnotation $a) => $this->withTeacherName($a));

        $observations = StudentObservation::where('student_id', $student->id)
            ->where(fn ($q) => $q->where('is_private', false)->orWhere('registered_by', $request->user()->id))
            ->with('registeredBy:id,name')
            ->orderByDesc('date')
            ->get()
            ->map(fn (StudentObservation $o) => $this->withTeacherName($o));

        $citations = ParentCitation::where('student_id', $student->id)
            ->with('parent:id,first_name,last_name,phone,relationship')
            ->orderByDesc('scheduled_date')
            ->get();

        $copyPayments = StudentCopyPayment::where('student_id', $student->id)
            ->with('copyCharge')
            ->get()
            ->sortByDesc(fn (StudentCopyPayment $p) => $p->copyCharge->charge_date)
            ->values();

        return response()->json([
            'student' => $student,
            'active_period' => $activePeriod,
            'grades' => $grades,
            'attendance_summary' => $attendanceSummary,
            'behavior' => $behavior,
            'observations' => $observations,
            'parents' => $student->parents()->get(),
            'citations' => $citations,
            'copy_payments' => $copyPayments,
        ]);
    }

    /**
     * @return array{0: Collection, 1: Collection}
     */
    private function buildGradesAndAttendance(Student $student, Collection $groupSubjects, ?Period $activePeriod): array
    {
        if (! $activePeriod) {
            return [collect(), collect()];
        }

        $grades = $groupSubjects->map(function (GroupSubject $gs) use ($student, $activePeriod) {
            $periodFinal = PeriodFinal::where('student_id', $student->id)
                ->where('group_subject_id', $gs->id)
                ->where('period_id', $activePeriod->id)
                ->first();

            return [
                'group_subject_id' => $gs->id,
                'subject_name' => $gs->subject->name,
                'subject_color' => $gs->subject->color,
                'group_name' => $gs->group->name,
                'period_final' => $periodFinal->period_final ?? null,
                'is_promoted' => $periodFinal->is_promoted ?? null,
            ];
        });

        $attendanceSummary = $groupSubjects->map(function (GroupSubject $gs) use ($student, $activePeriod) {
            $counts = AttendanceRecord::where('student_id', $student->id)
                ->where('group_subject_id', $gs->id)
                ->whereBetween('date', [$activePeriod->start_date, $activePeriod->end_date])
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            return [
                'group_subject_id' => $gs->id,
                'subject_name' => $gs->subject->name,
                'presente' => (int) ($counts['presente'] ?? 0),
                'ausente_injustificado' => (int) ($counts['ausente_injustificado'] ?? 0),
                'ausente_justificado' => (int) ($counts['ausente_justificado'] ?? 0),
                'tarde' => (int) ($counts['tarde'] ?? 0),
            ];
        });

        return [$grades, $attendanceSummary];
    }

    /**
     * Same fix as BehaviorAnnotationController::withTeacherName(): avoid the
     * `registered_by` FK / `registeredBy` relation JSON-key collision.
     */
    private function withTeacherName(BehaviorAnnotation|StudentObservation $model): array
    {
        $teacherName = $model->registeredBy?->name;
        $model->unsetRelation('registeredBy');

        $array = $model->toArray();
        $array['teacher_name'] = $teacherName;

        return $array;
    }
}
