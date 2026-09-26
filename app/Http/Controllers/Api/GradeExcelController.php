<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use App\Models\GradeColumn;
use App\Models\GradeConvention;
use App\Models\GradeSection;
use App\Models\GroupSubject;
use App\Models\Period;
use App\Models\Student;
use App\Services\GradeCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Cargue de notas desde Excel: el docente descarga una plantilla con sus
 * estudiantes y una columna por actividad manual del período, la llena y la sube.
 *
 * Formato de la plantilla (lo genera `template`, lo lee `import`):
 *  - Fila 1 (oculta): claves de máquina — "student_id" en A1 y "col:{id}" sobre cada actividad.
 *  - Fila 2: encabezados visibles (Apellidos, Nombres, "Sección · Actividad").
 *  - Fila 3 en adelante: un estudiante por fila; la columna A (oculta) guarda su id.
 *  - Si el docente tiene convenciones (NP, ✓…), una segunda hoja las lista y las
 *    celdas aceptan también esas abreviaturas.
 * Las columnas se ubican por esas claves, no por posición, así que reordenar o
 * borrar columnas en Excel no mezcla notas de una actividad con otra.
 */
class GradeExcelController extends Controller
{
    public function template(Request $request)
    {
        [$groupSubject, $period] = $this->resolve($request);
        $this->authorize('view', $groupSubject);

        $students = $this->students($groupSubject);
        $columns = $this->manualColumns($groupSubject, $period);
        $conventions = $this->conventions($groupSubject);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Notas');

        $sheet->setCellValue('A1', 'student_id');
        $sheet->setCellValue('A2', 'ID');
        $sheet->setCellValue('B2', 'Apellidos');
        $sheet->setCellValue('C2', 'Nombres');

        foreach ($columns->values() as $index => $column) {
            $letter = Coordinate::stringFromColumnIndex($index + 4);
            $sheet->setCellValue("{$letter}1", "col:{$column->id}");
            $sheet->setCellValue("{$letter}2", "{$column->gradeSection->name} · {$column->name}");
            $sheet->getStyle("{$letter}2")->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB(ltrim($column->gradeSection->color ?: '#1565C0', '#'));
            $sheet->getStyle("{$letter}2")->getFont()->getColor()->setRGB('FFFFFF');
            $sheet->getColumnDimension($letter)->setWidth(18);
        }

        foreach ($students->values() as $index => $student) {
            $row = $index + 3;
            $sheet->setCellValue("A{$row}", $student->id);
            $sheet->setCellValue("B{$row}", $student->last_name);
            $sheet->setCellValue("C{$row}", $student->first_name);
        }

        $lastRow = max(3, $students->count() + 2);
        // Con convenciones las celdas también llevan texto (NP, ✓…): la validación
        // numérica de Excel las rechazaría, así que solo se pone sin convenciones.
        foreach ($conventions->isEmpty() ? $columns->values() : [] as $index => $column) {
            $letter = Coordinate::stringFromColumnIndex($index + 4);
            $validation = $sheet->getCell("{$letter}3")->getDataValidation();
            $validation->setType(DataValidation::TYPE_DECIMAL)
                ->setOperator(DataValidation::OPERATOR_BETWEEN)
                ->setFormula1('1')
                ->setFormula2((string) (float) $column->max_score)
                ->setAllowBlank(true)
                ->setShowErrorMessage(true)
                ->setErrorTitle('Nota inválida')
                ->setError('La nota debe estar entre 1.0 y '.(float) $column->max_score.'.');
            $validation->setSqref("{$letter}3:{$letter}{$lastRow}");
        }

        $sheet->getStyle('A2:'.Coordinate::stringFromColumnIndex(max(3, $columns->count() + 3)).'2')->getFont()->setBold(true);
        $sheet->getRowDimension(1)->setVisible(false);
        $sheet->getColumnDimension('A')->setVisible(false);
        $sheet->getColumnDimension('B')->setWidth(24);
        $sheet->getColumnDimension('C')->setWidth(24);
        $sheet->freezePane('D3');

        if ($conventions->isNotEmpty()) {
            $legend = $spreadsheet->createSheet();
            $legend->setTitle('Convenciones');
            $legend->fromArray(['Escribe', 'Significado', 'Nota'], null, 'A1');
            foreach ($conventions->values() as $index => $convention) {
                $legend->fromArray([
                    $convention->code,
                    $convention->label,
                    $convention->value !== null ? (float) $convention->value : 'Sin nota (no cuenta en el promedio)',
                ], null, 'A'.($index + 2));
            }
            $legend->getStyle('A1:C1')->getFont()->setBold(true);
            $legend->getColumnDimension('B')->setWidth(28);
            $legend->getColumnDimension('C')->setWidth(36);
            $spreadsheet->setActiveSheetIndex(0);
        }

        $groupSubject->loadMissing(['group:id,name', 'subject:id,name']);
        $filename = "notas-{$groupSubject->group->name}-{$groupSubject->subject->name}-{$period->name}.xlsx";

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function import(Request $request, GradeCalculatorService $calculator)
    {
        [$groupSubject, $period] = $this->resolve($request);
        $request->validate([
            'file' => ['required', 'file', 'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,application/octet-stream,application/zip', 'max:5120'],
        ]);
        $this->authorize('update', $groupSubject);
        abort_if($period->is_closed, 422, 'El período está cerrado. Las notas no se pueden editar.');

        $rows = IOFactory::load($request->file('file')->getRealPath())
            ->getActiveSheet()
            ->toArray(null, true, false, false);

        $columns = $this->manualColumns($groupSubject, $period)->keyBy('id');
        $conventions = $this->conventions($groupSubject);

        // Ubicar cada actividad por su clave "col:{id}" de la fila 1.
        $columnIndexes = [];
        foreach ($rows[0] ?? [] as $index => $key) {
            if (is_string($key) && preg_match('/^col:(\d+)$/', trim($key), $m) && $columns->has((int) $m[1])) {
                $columnIndexes[$index] = $columns->get((int) $m[1]);
            }
        }
        if (trim((string) ($rows[0][0] ?? '')) !== 'student_id' || empty($columnIndexes)) {
            return response()->json([
                'message' => 'Este archivo no es una plantilla de notas de este curso y período. Descarga la plantilla desde la planilla y vuelve a intentarlo.',
            ], 422);
        }

        $enrolled = $this->students($groupSubject)->pluck('id')->flip();

        $saved = 0;
        $skipped = 0;
        $errors = [];
        $affectedStudents = [];

        Grade::withoutEvents(function () use ($rows, $columnIndexes, $enrolled, $conventions, $request, &$saved, &$skipped, &$errors, &$affectedStudents) {
            foreach (array_slice($rows, 2, null, true) as $rowIndex => $row) {
                $excelRow = $rowIndex + 1;
                $studentId = (int) ($row[0] ?? 0);
                if ($studentId === 0) {
                    continue;
                }
                if (! $enrolled->has($studentId)) {
                    $errors[] = "Fila {$excelRow}: el estudiante no está matriculado en este grupo.";

                    continue;
                }

                foreach ($columnIndexes as $index => $column) {
                    $raw = $row[$index] ?? null;
                    if ($raw === null || trim((string) $raw) === '') {
                        $skipped++;

                        continue;
                    }

                    $convention = $this->matchConvention($conventions, (string) $raw);
                    $score = is_numeric($raw) ? (float) $raw : (float) str_replace(',', '.', (string) $raw);
                    $isNumeric = is_numeric($raw) || is_numeric(str_replace(',', '.', (string) $raw));
                    if (! $convention && (! $isNumeric || $score < 1.0 || $score > (float) $column->max_score)) {
                        $hint = $conventions->isNotEmpty() ? ' ni una de tus convenciones' : '';
                        $errors[] = "Fila {$excelRow}, {$column->name}: \"{$raw}\" no es una nota válida (1.0 a ".(float) $column->max_score."){$hint}.";

                        continue;
                    }

                    Grade::updateOrCreate(
                        ['student_id' => $studentId, 'grade_column_id' => $column->id],
                        [
                            'group_subject_id' => $column->group_subject_id,
                            'period_id' => $column->period_id,
                            'score' => $convention ? $convention->scoreFor($column) : round($score, 1),
                            'convention_id' => $convention?->id,
                            'registered_by' => $request->user()->id,
                        ]
                    );
                    $saved++;
                    $affectedStudents[$studentId] = true;
                }
            }
        });

        foreach (array_keys($affectedStudents) as $studentId) {
            $calculator->recalculateForStudent($studentId, $groupSubject->id, $period->id);
        }

        return response()->json(['saved' => $saved, 'skipped' => $skipped, 'errors' => $errors]);
    }

    /**
     * @return array{0: GroupSubject, 1: Period}
     */
    private function resolve(Request $request): array
    {
        $request->validate([
            'groupSubjectId' => ['required', 'integer', 'exists:group_subjects,id'],
            'periodId' => ['required', 'integer', 'exists:periods,id'],
        ]);

        return [GroupSubject::findOrFail($request->groupSubjectId), Period::findOrFail($request->periodId)];
    }

    private function students(GroupSubject $groupSubject): Collection
    {
        return Student::whereHas(
            'studentGroups',
            fn ($q) => $q->where('group_id', $groupSubject->group_id)->where('status', 'activo')
        )->orderBy('last_name')->orderBy('first_name')->get(['id', 'first_name', 'last_name']);
    }

    private function conventions(GroupSubject $groupSubject): Collection
    {
        return GradeConvention::where('user_id', $groupSubject->user_id)->orderBy('sort_order')->orderBy('id')->get();
    }

    /** Coincidencia exacta primero; si no, sin distinguir mayúsculas ("np" = "NP"). */
    private function matchConvention(Collection $conventions, string $raw): ?GradeConvention
    {
        $raw = trim($raw);

        return $conventions->first(fn ($c) => $c->code === $raw)
            ?? $conventions->first(fn ($c) => mb_strtolower($c->code) === mb_strtolower($raw));
    }

    /** Solo columnas manuales: las calculadas (asistencia, fórmulas) no se digitan. */
    private function manualColumns(GroupSubject $groupSubject, Period $period): Collection
    {
        $sections = GradeSection::where('group_subject_id', $groupSubject->id)
            ->where('period_id', $period->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return GradeColumn::whereIn('grade_section_id', $sections->pluck('id'))
            ->where('column_type', 'manual')
            ->where('is_visible', true)
            ->get()
            ->sortBy(fn ($c) => [$sections->search(fn ($s) => $s->id === $c->grade_section_id), $c->sort_order])
            ->each(fn ($c) => $c->setRelation('gradeSection', $sections->firstWhere('id', $c->grade_section_id)))
            ->values();
    }
}
