<?php

namespace App\Services\Reports;

use App\Models\Grade;
use App\Models\GroupSubject;
use App\Models\Institution;
use App\Models\Period;
use App\Models\PeriodFinal;
use App\Models\Report;
use Illuminate\Support\Str;

/**
 * Reporte de riesgo académico (módulo 16.E): estudiantes de toda la
 * institución con una definitiva de período por debajo de la nota mínima
 * aprobatoria, con el detalle de qué columnas reprobaron en esa materia.
 */
class AcademicRiskReportService
{
    public function generate(Report $report): string
    {
        $params = $report->params;
        $institution = Institution::findOrFail($params['institution_id']);
        $period = Period::findOrFail($params['period_id']);
        $minPassing = (float) $institution->min_passing_grade;

        $groupSubjectIds = GroupSubject::where('institution_id', $institution->id)->pluck('id');

        $atRisk = PeriodFinal::whereIn('group_subject_id', $groupSubjectIds)
            ->where('period_id', $period->id)
            ->whereNotNull('period_final')
            ->where('period_final', '<', $minPassing)
            ->with(['student', 'groupSubject.subject', 'groupSubject.group'])
            ->get()
            ->sortBy(fn (PeriodFinal $pf) => $pf->student->last_name)
            ->values();

        $rows = $atRisk->map(function (PeriodFinal $pf) use ($period, $minPassing) {
            $failingColumns = Grade::where('student_id', $pf->student_id)
                ->where('group_subject_id', $pf->group_subject_id)
                ->where('period_id', $period->id)
                ->whereNotNull('score')
                ->where('score', '<', $minPassing)
                ->with('gradeColumn')
                ->get()
                ->map(fn (Grade $g) => $g->gradeColumn->short_name ?: $g->gradeColumn->name)
                ->implode(', ');

            return [
                "{$pf->student->last_name} {$pf->student->first_name}",
                $pf->groupSubject->group->name,
                $pf->groupSubject->subject->name,
                number_format((float) $pf->period_final, 1),
                $failingColumns ?: '—',
            ];
        })->all();

        $headers = ['Estudiante', 'Grupo', 'Materia', 'Definitiva', 'Columnas reprobadas'];
        $title = "Reporte de Riesgo Académico — {$period->name}";
        $metaLines = ["Institución: {$institution->name}", 'Nota mínima aprobatoria: '.number_format($minPassing, 1)];

        $extension = $params['format'] === 'pdf' ? 'pdf' : 'xlsx';
        $relativePath = "reports/{$institution->id}/{$report->user_id}/".Str::uuid().'.'.$extension;

        if ($params['format'] === 'pdf') {
            PdfTableRenderer::render($relativePath, 'reports.generic-table', [
                'title' => $title, 'metaLines' => $metaLines, 'headers' => $headers, 'rows' => $rows,
            ]);
        } else {
            ExcelTableWriter::write($relativePath, $title, $metaLines, $headers, $rows);
        }

        return $relativePath;
    }
}
