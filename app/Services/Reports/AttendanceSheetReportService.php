<?php

namespace App\Services\Reports;

use App\Models\AttendanceRecord;
use App\Models\GroupSubject;
use App\Models\Period;
use App\Models\Report;
use App\Models\Student;
use App\Models\StudentGroup;
use Illuminate\Support\Str;

/**
 * Reporte de asistencia (módulo 16.C): una columna por fecha con registro
 * dentro del período, una fila por estudiante activo del grupo.
 */
class AttendanceSheetReportService
{
    private const STATUS_LETTER = [
        'presente' => 'P',
        'ausente_injustificado' => 'A',
        'ausente_justificado' => 'J',
        'tarde' => 'T',
    ];

    public function generate(Report $report): string
    {
        $params = $report->params;
        $groupSubject = GroupSubject::with('subject', 'group', 'institution')->findOrFail($params['group_subject_id']);
        $period = Period::findOrFail($params['period_id']);

        $studentIds = StudentGroup::where('group_id', $groupSubject->group_id)
            ->where('status', 'activo')
            ->pluck('student_id');
        $students = Student::whereIn('id', $studentIds)->orderBy('last_name')->orderBy('first_name')->get();

        $dates = AttendanceRecord::where('group_subject_id', $groupSubject->id)
            ->whereBetween('date', [$period->start_date, $period->end_date])
            ->distinct()
            ->orderBy('date')
            ->pluck('date');

        $records = AttendanceRecord::where('group_subject_id', $groupSubject->id)
            ->whereIn('student_id', $studentIds)
            ->whereBetween('date', [$period->start_date, $period->end_date])
            ->get()
            ->keyBy(fn (AttendanceRecord $r) => $r->student_id.'_'.$r->date->format('Y-m-d'));

        $headers = array_merge(['Estudiante'], $dates->map(fn ($d) => $d->format('d/m'))->all());
        $rows = [];
        foreach ($students as $student) {
            $row = ["{$student->last_name} {$student->first_name}"];
            foreach ($dates as $date) {
                $record = $records->get($student->id.'_'.$date->format('Y-m-d'));
                $row[] = $record ? (self::STATUS_LETTER[$record->status] ?? '') : '';
            }
            $rows[] = $row;
        }

        $title = "Reporte de Asistencia — {$groupSubject->subject->name} — {$groupSubject->group->name} — {$period->name}";
        $metaLines = ["Institución: {$groupSubject->institution->name}", 'Referencias: P=Presente, A=Ausente injustificado, J=Ausente justificado, T=Tarde'];

        $extension = $params['format'] === 'pdf' ? 'pdf' : 'xlsx';
        $relativePath = "reports/{$groupSubject->institution_id}/{$report->user_id}/".Str::uuid().'.'.$extension;

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
