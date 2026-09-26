<?php

namespace App\Services\Reports;

use App\Models\Grade;
use App\Models\GradeSection;
use App\Models\GroupSubject;
use App\Models\Period;
use App\Models\PeriodFinal;
use App\Models\Report;
use App\Models\SectionFinal;
use App\Models\Student;
use App\Models\StudentGroup;
use App\Services\PerformanceScale;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Planilla de calificaciones (módulo 16.A): mirrors the visual template used by
 * GradeSheetTable.tsx on the frontend — merged, colored section headers, one
 * column per grade_column plus a "Def" column per section with
 * has_section_final, a final "Def Total" column, red/yellow/green cell color
 * coding against institution->min_passing_grade (same thresholds as
 * gradeHelpers.ts::getGradeColor), and a footer with group stats + signature.
 */
class GradeSheetReportService
{
    /** Niveles de la escala de valoración de la institución (colores de las celdas). */
    private $levels;

    public function generate(Report $report): string
    {
        $params = $report->params;
        $groupSubject = GroupSubject::with('subject', 'group', 'teacher', 'institution')
            ->findOrFail($params['group_subject_id']);
        $period = Period::findOrFail($params['period_id']);
        $institution = $groupSubject->institution;
        $minPassing = (float) $institution->min_passing_grade;
        $this->levels = $institution->performanceLevels()->get();

        $sections = GradeSection::where('group_subject_id', $groupSubject->id)
            ->where('period_id', $period->id)
            ->where('is_active', true)
            ->with(['columns' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        $studentIds = StudentGroup::where('group_id', $groupSubject->group_id)
            ->where('status', 'activo')
            ->pluck('student_id');

        $students = Student::whereIn('id', $studentIds)
            ->orderBy('last_name')->orderBy('first_name')->get();

        $gradesByStudent = Grade::where('group_subject_id', $groupSubject->id)
            ->where('period_id', $period->id)
            ->whereIn('student_id', $studentIds)
            ->with('convention:id,code')
            ->get()
            ->groupBy('student_id');

        $sectionFinalsByStudent = SectionFinal::where('group_subject_id', $groupSubject->id)
            ->where('period_id', $period->id)
            ->whereIn('student_id', $studentIds)
            ->get()
            ->groupBy('student_id');

        $periodFinalsByStudent = PeriodFinal::where('group_subject_id', $groupSubject->id)
            ->where('period_id', $period->id)
            ->whereIn('student_id', $studentIds)
            ->get()
            ->keyBy('student_id');

        $logoPath = $institution->logo ? Storage::disk('local')->path($institution->logo) : null;

        $context = [
            'institution' => $institution,
            'logoPath' => $logoPath,
            'groupSubject' => $groupSubject,
            'period' => $period,
            'minPassing' => $minPassing,
            'colorFor' => fn (?float $value) => $value === null ? null : $this->colorFor($value, $minPassing),
            'sections' => $sections,
            'students' => $students,
            'gradesByStudent' => $gradesByStudent,
            'sectionFinalsByStudent' => $sectionFinalsByStudent,
            'periodFinalsByStudent' => $periodFinalsByStudent,
        ];

        $extension = $params['format'] === 'pdf' ? 'pdf' : 'xlsx';
        $relativePath = "reports/{$institution->id}/{$report->user_id}/".Str::uuid().'.'.$extension;

        if ($params['format'] === 'pdf') {
            PdfTableRenderer::render($relativePath, 'reports.grade-sheet', $context);
        } else {
            $this->writeExcel($relativePath, $context);
        }

        return $relativePath;
    }

    private function writeExcel(string $relativePath, array $context): void
    {
        [
            'institution' => $institution, 'logoPath' => $logoPath, 'groupSubject' => $groupSubject, 'period' => $period,
            'minPassing' => $minPassing, 'sections' => $sections, 'students' => $students,
            'gradesByStudent' => $gradesByStudent, 'sectionFinalsByStudent' => $sectionFinalsByStudent,
            'periodFinalsByStudent' => $periodFinalsByStudent,
        ] = $context;

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        if ($logoPath) {
            (new Drawing)
                ->setPath($logoPath)
                ->setHeight(50)
                ->setCoordinates('A1')
                ->setWorksheet($sheet);
        }

        // Estudiante + one column per grade_column + one "Def" per section with a final + "Def Total"
        $totalColumns = 1 + $sections->sum(fn ($s) => $s->columns->count() + ($s->has_section_final ? 1 : 0)) + 1;
        $lastColumn = Coordinate::stringFromColumnIndex($totalColumns);

        $row = 1;
        $sheet->setCellValue("A{$row}", $institution->name);
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(14);
        $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
        $row++;

        $sheet->setCellValue("A{$row}", "Planilla de Calificaciones — {$groupSubject->subject->name} — {$groupSubject->group->name} — {$period->name}");
        $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
        $row++;

        $sheet->setCellValue("A{$row}", 'Docente: '.($groupSubject->teacher->name ?? ''));
        $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
        $row += 2;

        $sectionHeaderRow = $row;
        $columnHeaderRow = $row + 1;
        $sheet->setCellValue("A{$sectionHeaderRow}", '');
        $sheet->mergeCells("A{$sectionHeaderRow}:A{$columnHeaderRow}");
        $sheet->setCellValue("A{$columnHeaderRow}", 'Estudiante');

        $colIndex = 2;
        $columnOrder = []; // [['type' => 'column'|'section_final'|'period_final', 'id' => int|null]]
        foreach ($sections as $section) {
            $span = $section->columns->count() + ($section->has_section_final ? 1 : 0);
            if ($span === 0) {
                continue;
            }
            $startCol = Coordinate::stringFromColumnIndex($colIndex);
            $endCol = Coordinate::stringFromColumnIndex($colIndex + $span - 1);
            $sheet->setCellValue("{$startCol}{$sectionHeaderRow}", $section->name);
            $sheet->mergeCells("{$startCol}{$sectionHeaderRow}:{$endCol}{$sectionHeaderRow}");
            $sheet->getStyle("{$startCol}{$sectionHeaderRow}")->getFill()
                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(ltrim($section->color ?? '1565C0', '#'));
            $sheet->getStyle("{$startCol}{$sectionHeaderRow}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');

            foreach ($section->columns as $column) {
                $col = Coordinate::stringFromColumnIndex($colIndex);
                $sheet->setCellValue("{$col}{$columnHeaderRow}", $column->short_name ?: $column->name);
                $columnOrder[] = ['type' => 'column', 'id' => $column->id, 'is_computed' => $column->column_type !== 'manual'];
                $colIndex++;
            }

            if ($section->has_section_final) {
                $col = Coordinate::stringFromColumnIndex($colIndex);
                $sheet->setCellValue("{$col}{$columnHeaderRow}", $section->section_final_label ?: 'Def');
                $columnOrder[] = ['type' => 'section_final', 'id' => $section->id, 'is_computed' => true];
                $colIndex++;
            }
        }

        $defTotalCol = Coordinate::stringFromColumnIndex($colIndex);
        $sheet->setCellValue("{$defTotalCol}{$sectionHeaderRow}", '');
        $sheet->mergeCells("{$defTotalCol}{$sectionHeaderRow}:{$defTotalCol}{$columnHeaderRow}");
        $sheet->setCellValue("{$defTotalCol}{$columnHeaderRow}", 'Def Total');

        $sheet->getStyle("A{$columnHeaderRow}:{$lastColumn}{$columnHeaderRow}")->getFont()->setBold(true);
        $row = $columnHeaderRow + 1;

        $periodFinalValues = [];

        foreach ($students as $student) {
            $sheet->setCellValue("A{$row}", "{$student->last_name} {$student->first_name}");

            $grades = ($gradesByStudent->get($student->id) ?? collect())->keyBy('grade_column_id');
            $sectionFinals = ($sectionFinalsByStudent->get($student->id) ?? collect())->keyBy('grade_section_id');

            $colIndex = 2;
            foreach ($columnOrder as $entry) {
                $col = Coordinate::stringFromColumnIndex($colIndex);
                $grade = $entry['type'] === 'column' ? $grades->get($entry['id']) : null;
                $value = $entry['type'] === 'column'
                    ? $grade?->score
                    : $sectionFinals->get($entry['id'])?->section_final;

                // Una nota puesta con convención se imprime con su abreviatura (NP, ✓…).
                $sheet->setCellValue("{$col}{$row}", $grade?->convention
                    ? $grade->convention->code
                    : ($value !== null ? (float) $value : ''));

                if ($entry['type'] === 'section_final' && $value !== null) {
                    $sheet->getStyle("{$col}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setRGB($this->colorFor((float) $value, $minPassing));
                } elseif ($entry['is_computed']) {
                    $sheet->getStyle("{$col}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setRGB('EEEEEE');
                }

                $colIndex++;
            }

            $periodFinal = $periodFinalsByStudent->get($student->id)?->period_final;
            $col = Coordinate::stringFromColumnIndex($colIndex);
            $sheet->setCellValue("{$col}{$row}", $periodFinal !== null ? (float) $periodFinal : '');
            if ($periodFinal !== null) {
                $periodFinalValues[] = (float) $periodFinal;
                $sheet->getStyle("{$col}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB($this->colorFor((float) $periodFinal, $minPassing));
                $sheet->getStyle("{$col}{$row}")->getFont()->setBold(true);
            }

            $row++;
        }

        $row++;
        $approved = count(array_filter($periodFinalValues, fn ($v) => $v >= $minPassing));
        $total = count($periodFinalValues);
        $average = $total > 0 ? array_sum($periodFinalValues) / $total : null;

        $footer = [
            'Promedio del grupo: '.($average !== null ? number_format($average, 1) : '—'),
            '% Aprobados: '.($total > 0 ? round(($approved / $total) * 100).'%' : '—'),
            'Máxima: '.($periodFinalValues !== [] ? number_format(max($periodFinalValues), 1) : '—'),
            'Mínima: '.($periodFinalValues !== [] ? number_format(min($periodFinalValues), 1) : '—'),
        ];
        foreach ($footer as $line) {
            $sheet->setCellValue("A{$row}", $line);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $row++;
        }

        $row += 2;
        $sheet->setCellValue("A{$row}", 'Firma del docente: _____________________________');

        foreach (range(1, $totalColumns) as $c) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }

        $absolutePath = Storage::disk('local')->path($relativePath);
        File::ensureDirectoryExists(dirname($absolutePath));
        (new Xlsx($spreadsheet))->save($absolutePath);
    }

    /**
     * Fondo de la celda: el color del nivel de la escala institucional, aclarado.
     * Sin escala (no debería pasar) cae al rojo/verde de antes según la nota mínima.
     */
    private function colorFor(float $value, float $minPassing): string
    {
        $level = PerformanceScale::levelFor($this->levels ?? collect(), $value);

        return $level
            ? PerformanceScale::tint($level->color)
            : ($value < $minPassing ? 'FFCDD2' : 'C8E6C9');
    }
}
