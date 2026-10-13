<?php

namespace App\Services\Reports;

use App\Models\Institution;
use App\Models\Period;
use App\Models\Report;
use App\Models\Student;
use App\Models\StudentGroup;
use App\Models\User;
use App\Services\GroupOverviewService;
use App\Services\PerformanceScale;
use Illuminate\Support\Collection;
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
    public function __construct(private GroupOverviewService $overview) {}

    public function generate(Report $report): string
    {
        $params = $report->params;
        $student = Student::with('institution')->findOrFail($params['student_id']);
        $period = Period::findOrFail($params['period_id']);

        $group = StudentGroup::where('student_id', $student->id)
            ->where('academic_year_id', $period->academic_year_id)
            ->where('status', 'activo')
            ->with('group.director:id,name')
            ->first()?->group;

        $relativePath = "reports/{$student->institution_id}/{$report->user_id}/".Str::uuid().'.pdf';
        // Las notas de todas las materias son del administrador y del director del grupo;
        // cualquier otro docente recibe el boletín solo con las materias que dicta.
        $requester = User::find($report->user_id);
        $seesAll = $requester && ($requester->isAdminOf($student->institution_id) || $group?->isDirectedBy($requester));
        $this->render($relativePath, $student->institution, $period, collect([[$student, $group, null]]), $seesAll ? null : (int) $report->user_id);

        return $relativePath;
    }

    /**
     * Uno o varios boletines en un solo PDF (una página por estudiante).
     *
     * @param  Collection<int, array{0: Student, 1: ?\App\Models\Group, 2: ?array}>  $entries  estudiante, grupo y su fila del consolidado
     */
    public function render(string $relativePath, Institution $institution, Period $period, Collection $entries, ?int $onlyTeacherId = null): void
    {
        $levels = $institution->performanceLevels()->get();

        PdfTableRenderer::render($relativePath, 'reports.student-bulletin', [
            'institution' => $institution,
            'logoPath' => $institution->logo ? Storage::disk('local')->path($institution->logo) : null,
            'levels' => $levels,
            'nationalLabels' => PerformanceScale::NATIONAL_LABELS,
            'bulletins' => $entries->map(fn ($e) => $this->overview->bulletin($e[0], $e[1], $period, $levels, $e[2], $onlyTeacherId))->all(),
        ]);
    }
}
