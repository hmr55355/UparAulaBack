<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupSubject;
use App\Models\Period;
use App\Models\Report;
use App\Models\Student;
use App\Services\GroupOverviewService;
use App\Services\PerformanceScale;
use App\Services\Reports\ExcelTableWriter;
use App\Services\Reports\GradeSheetReportService;
use App\Services\Reports\StudentBulletinReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Dirección de grupo: el director (o un administrador) ve todas las materias de su
 * grupo, descarga el consolidado y los boletines. Las descargas se generan en la
 * misma petición (sin cola): en el hosting no siempre hay un worker corriendo, y
 * un grupo de 30–40 estudiantes se genera en pocos segundos.
 */
class GroupDirectorController extends Controller
{
    public function __construct(private GroupOverviewService $overview) {}

    public function consolidated(Request $request, Group $group)
    {
        $period = $this->authorizeAndResolvePeriod($request, $group);
        $data = $this->overview->consolidated($group, $period);

        return response()->json([
            'group' => $group->load('director:id,name')->only(['id', 'name', 'director_id', 'director']),
            'period' => $period->only(['id', 'name']),
            'min_passing_grade' => $data['min_passing'],
            'subjects' => $data['subjects']->map(fn (GroupSubject $gs) => [
                'group_subject_id' => $gs->id,
                'name' => $gs->subject->name,
                'color' => $gs->subject->color,
                'teacher' => $gs->teacher?->name,
                ...$data['subject_stats'][$gs->id],
            ]),
            'students' => $data['students'],
            'rows' => $data['rows'],
        ]);
    }

    public function consolidatedExport(Request $request, Group $group)
    {
        $period = $this->authorizeAndResolvePeriod($request, $group);
        $format = $request->validate(['format' => ['required', 'in:excel,pdf']])['format'];
        $data = $this->overview->consolidated($group, $period);
        $levels = $group->institution->performanceLevels()->get();

        $subjects = $data['subjects'];
        $students = $data['students']->keyBy('id');
        $headers = ['#', 'Estudiante', ...$subjects->map(fn ($gs) => $gs->subject->name)->all(), 'Promedio', 'Puesto', 'Perdidas', 'Faltas'];
        $rows = [];
        $colors = [];
        foreach ($data['rows'] as $index => $row) {
            $student = $students[$row['student_id']];
            $grades = $subjects->map(fn ($gs) => $row['grades'][$gs->id])->all();
            $rows[] = [
                $index + 1,
                "{$student->last_name} {$student->first_name}",
                ...array_map(fn ($g) => $g !== null ? number_format($g, 1) : '', $grades),
                $row['average'] !== null ? number_format($row['average'], 2) : '',
                $row['rank'] ?? '',
                $row['failed'],
                $row['absences'],
            ];
            foreach ($grades as $offset => $grade) {
                $level = PerformanceScale::levelFor($levels, $grade);
                if ($level) {
                    $colors[$index][$offset + 2] = PerformanceScale::tint($level->color);
                }
            }
        }
        $rows[] = ['', 'Promedio del grupo', ...$subjects->map(fn ($gs) => $data['subject_stats'][$gs->id]['average'] !== null
            ? number_format($data['subject_stats'][$gs->id]['average'], 2) : '')->all(), '', '', '', ''];
        $rows[] = ['', 'Estudiantes con desempeño bajo', ...$subjects->map(fn ($gs) => $data['subject_stats'][$gs->id]['failed'])->all(), '', '', '', ''];

        $title = "Consolidado de notas — {$group->name}";
        $meta = [
            $group->institution->name,
            "{$period->name} · Director(a) de grupo: ".($group->director?->name ?? 'sin asignar'),
            'Generado el '.now()->format('d/m/Y'),
        ];
        $relativePath = $this->tempPath($request, $group, $format === 'pdf' ? 'pdf' : 'xlsx');

        if ($format === 'pdf') {
            $absolute = Storage::disk('local')->path($relativePath);
            File::ensureDirectoryExists(dirname($absolute));
            Pdf::loadView('reports.generic-table', [
                'title' => $title, 'metaLines' => $meta, 'headers' => $headers, 'rows' => $rows, 'cellColors' => $colors,
            ])->setPaper('letter', 'landscape')->save($absolute);
        } else {
            ExcelTableWriter::write($relativePath, $title, $meta, $headers, $rows, fn ($r, $c) => $colors[$r][$c] ?? null);
        }

        return $this->download($relativePath, "consolidado-{$group->name}-{$period->name}");
    }

    /** Boletines del grupo en un solo PDF, o el de un estudiante (studentId). */
    public function bulletins(Request $request, Group $group, StudentBulletinReportService $bulletins)
    {
        $period = $this->authorizeAndResolvePeriod($request, $group);
        $validated = $request->validate(['studentId' => ['nullable', 'integer']]);
        $data = $this->overview->consolidated($group, $period);
        $summaries = collect($data['rows'])->keyBy('student_id');

        $students = Student::whereIn('id', $data['students']->pluck('id'))
            ->when($validated['studentId'] ?? null, fn ($q, $id) => $q->where('id', $id))
            ->orderBy('last_name')->orderBy('first_name')->get();
        abort_if($students->isEmpty(), 404, 'El estudiante no está activo en este grupo.');

        $group->load('director:id,name');
        $relativePath = $this->tempPath($request, $group, 'pdf');
        $bulletins->render(
            $relativePath,
            $group->institution,
            $period,
            $students->map(fn (Student $s) => [$s, $group, $summaries->get($s->id)])
        );

        $name = $students->count() === 1
            ? "boletin-{$students[0]->last_name}-{$students[0]->first_name}-{$period->name}"
            : "boletines-{$group->name}-{$period->name}";

        return $this->download($relativePath, $name);
    }

    /** Planilla de un curso (notas y definitivas) en Excel o PDF, descarga directa. */
    public function gradeSheet(Request $request)
    {
        $validated = $request->validate([
            'groupSubjectId' => ['required', 'integer', 'exists:group_subjects,id'],
            'periodId' => ['required', 'integer', 'exists:periods,id'],
            'format' => ['required', 'in:excel,pdf'],
        ]);
        $groupSubject = GroupSubject::with(['group', 'subject'])->findOrFail($validated['groupSubjectId']);
        $this->authorize('view', $groupSubject);
        $period = Period::findOrFail($validated['periodId']);

        // GradeSheetReportService trabaja sobre un Report; se deja registrado como completado.
        $report = Report::create([
            'user_id' => $request->user()->id,
            'institution_id' => $groupSubject->institution_id,
            'type' => 'grade_sheet',
            'format' => $validated['format'],
            'params' => ['group_subject_id' => $groupSubject->id, 'period_id' => $period->id, 'format' => $validated['format']],
            'status' => 'processing',
        ]);
        $path = (new GradeSheetReportService)->generate($report);
        $report->update(['status' => 'completed', 'file_path' => $path]);

        return Storage::disk('local')->download(
            $path,
            Str::slug("planilla-{$groupSubject->group->name}-{$groupSubject->subject->name}-{$period->name}").'.'.pathinfo($path, PATHINFO_EXTENSION)
        );
    }

    private function authorizeAndResolvePeriod(Request $request, Group $group): Period
    {
        abort_unless($group->canBeOverseenBy($request->user()), 403, 'Solo el director del grupo o un administrador puede ver esto.');
        $request->validate(['periodId' => ['required', 'integer']]);

        return Period::where('academic_year_id', $group->academic_year_id)->findOrFail($request->periodId);
    }

    private function tempPath(Request $request, Group $group, string $extension): string
    {
        return "reports/{$group->institution_id}/{$request->user()->id}/".Str::uuid().'.'.$extension;
    }

    private function download(string $relativePath, string $name)
    {
        $extension = pathinfo($relativePath, PATHINFO_EXTENSION);

        return response()
            ->download(Storage::disk('local')->path($relativePath), Str::slug($name).'.'.$extension)
            ->deleteFileAfterSend();
    }
}
