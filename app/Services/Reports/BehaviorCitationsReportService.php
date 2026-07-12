<?php

namespace App\Services\Reports;

use App\Models\BehaviorAnnotation;
use App\Models\Group;
use App\Models\ParentCitation;
use App\Models\Period;
use App\Models\Report;
use Illuminate\Support\Str;

/**
 * Reporte de citaciones y comportamiento (módulo 16.D): listado combinado de
 * anotaciones y citaciones del grupo dentro del rango de fechas del período.
 */
class BehaviorCitationsReportService
{
    public function generate(Report $report): string
    {
        $params = $report->params;
        $group = Group::with('institution')->findOrFail($params['group_id']);
        $period = Period::findOrFail($params['period_id']);

        $annotations = BehaviorAnnotation::where('group_id', $group->id)
            ->whereBetween('date', [$period->start_date, $period->end_date])
            ->with('student')
            ->get()
            ->map(fn (BehaviorAnnotation $a) => [
                'date' => $a->date->format('d/m/Y'),
                'student' => "{$a->student->last_name} {$a->student->first_name}",
                'type' => 'Anotación ('.$a->type.')',
                'reason' => $a->category ?: $a->title,
                'status' => $a->requires_parent_contact
                    ? ($a->parent_contacted ? 'Padre contactado' : 'Pendiente contactar padre')
                    : '—',
                'sort' => $a->date,
            ]);

        $citations = ParentCitation::where('group_id', $group->id)
            ->whereBetween('scheduled_date', [$period->start_date, $period->end_date])
            ->with('student')
            ->get()
            ->map(fn (ParentCitation $c) => [
                'date' => $c->scheduled_date->format('d/m/Y'),
                'student' => "{$c->student->last_name} {$c->student->first_name}",
                'type' => 'Citación',
                'reason' => $c->reason,
                'status' => $c->status,
                'sort' => $c->scheduled_date,
            ]);

        $entries = $annotations->concat($citations)->sortBy('sort')->values();

        $headers = ['Fecha', 'Estudiante', 'Tipo', 'Categoría/Motivo', 'Estado'];
        $rows = $entries->map(fn ($e) => [$e['date'], $e['student'], $e['type'], $e['reason'], $e['status']])->all();

        $title = "Reporte de Comportamiento y Citaciones — {$group->name} — {$period->name}";
        $metaLines = ["Institución: {$group->institution->name}"];

        $extension = $params['format'] === 'pdf' ? 'pdf' : 'xlsx';
        $relativePath = "reports/{$group->institution_id}/{$report->user_id}/".Str::uuid().'.'.$extension;

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
