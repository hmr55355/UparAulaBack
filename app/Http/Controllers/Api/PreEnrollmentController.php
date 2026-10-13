<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\PreEnrollment;
use App\Models\Student;
use App\Services\Reports\ExcelTableWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Prematrícula para el año siguiente, por grupo. La hacen los administradores y el
 * director del grupo: por cada estudiante, si continúa (y a qué grado), si no
 * continúa, o si aún está pendiente. No matricula a nadie: es el listado con que
 * la institución arma los grupos del año nuevo.
 */
class PreEnrollmentController extends Controller
{
    private const LABELS = ['pendiente' => 'Pendiente', 'confirmada' => 'Confirmada', 'no_continua' => 'No continúa'];

    public function index(Request $request, Group $group)
    {
        [$targetYear, $students, $existing, $suggested] = $this->load($request, $group);

        $rows = $students->map(function (Student $student) use ($existing, $suggested) {
            $pre = $existing->get($student->id);

            return [
                'student' => $student->only(['id', 'first_name', 'last_name', 'document_type', 'document_number']),
                'status' => $pre->status ?? 'pendiente',
                'target_grade_level_id' => $pre ? $pre->target_grade_level_id : $suggested?->id,
                'notes' => $pre->notes ?? null,
                'saved' => (bool) $pre,
                'updated_at' => $pre?->updated_at,
            ];
        });

        return response()->json([
            'target_year' => $targetYear,
            'suggested_grade_level_id' => $suggested?->id,
            'grade_levels' => $group->institution->gradeLevels()->orderBy('level')->orderBy('name')->get(['id', 'name', 'level']),
            'rows' => $rows,
            'summary' => $rows->countBy('status'),
        ]);
    }

    public function save(Request $request, Group $group)
    {
        abort_unless($group->canBeOverseenBy($request->user()), 403, 'Solo el director del grupo o un administrador puede hacer la prematrícula.');

        $validated = $request->validate([
            'target_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.student_id' => ['required', 'integer'],
            'items.*.status' => ['required', Rule::in(PreEnrollment::STATUSES)],
            'items.*.target_grade_level_id' => ['nullable', 'integer',
                Rule::exists('grade_levels', 'id')->where('institution_id', $group->institution_id)],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        $enrolled = $group->studentGroups()->where('status', 'activo')->pluck('student_id')->flip();

        DB::transaction(function () use ($validated, $group, $enrolled, $request) {
            foreach ($validated['items'] as $item) {
                abort_unless($enrolled->has($item['student_id']), 422, 'Uno de los estudiantes no está activo en este grupo.');

                PreEnrollment::updateOrCreate(
                    ['student_id' => $item['student_id'], 'target_year' => $validated['target_year']],
                    [
                        'institution_id' => $group->institution_id,
                        'from_group_id' => $group->id,
                        'status' => $item['status'],
                        // "No continúa" no tiene grado de destino.
                        'target_grade_level_id' => $item['status'] === 'no_continua' ? null : ($item['target_grade_level_id'] ?? null),
                        'notes' => $item['notes'] ?? null,
                        'registered_by' => $request->user()->id,
                    ]
                );
            }
        });

        return $this->index($request->merge(['targetYear' => $validated['target_year']]), $group);
    }

    public function export(Request $request, Group $group)
    {
        [$targetYear, $students, $existing, $suggested] = $this->load($request, $group);
        $levels = GradeLevel::where('institution_id', $group->institution_id)->pluck('name', 'id');

        $rows = $students->values()->map(function (Student $student, int $index) use ($existing, $suggested, $levels) {
            $pre = $existing->get($student->id);
            $status = $pre->status ?? 'pendiente';
            $levelId = $pre ? $pre->target_grade_level_id : $suggested?->id;

            return [
                $index + 1,
                "{$student->last_name} {$student->first_name}",
                trim("{$student->document_type} {$student->document_number}"),
                self::LABELS[$status],
                $status === 'no_continua' ? '' : ($levels[$levelId] ?? ''),
                $pre->notes ?? '',
                '',
            ];
        })->all();

        $counts = collect($rows)->countBy(fn ($r) => $r[3]);
        $relativePath = "reports/{$group->institution_id}/{$request->user()->id}/".Str::uuid().'.xlsx';
        ExcelTableWriter::write(
            $relativePath,
            "Prematrícula {$targetYear} — grupo {$group->name}",
            [$group->institution->name, 'Director(a) de grupo: '.($group->director?->name ?? 'sin asignar'), 'Generado el '.now()->format('d/m/Y')],
            ['#', 'Estudiante', 'Documento', 'Estado', "Grado {$targetYear}", 'Observación', 'Firma del acudiente'],
            $rows,
            null,
            collect(self::LABELS)->map(fn ($label) => "{$label}: ".($counts[$label] ?? 0))->values()->all()
        );

        return response()
            ->download(Storage::disk('local')->path($relativePath), Str::slug("prematricula-{$targetYear}-{$group->name}").'.xlsx')
            ->deleteFileAfterSend();
    }

    /** @return array{0: int, 1: \Illuminate\Support\Collection, 2: \Illuminate\Support\Collection, 3: ?GradeLevel} */
    private function load(Request $request, Group $group): array
    {
        abort_unless($group->canBeOverseenBy($request->user()), 403, 'Solo el director del grupo o un administrador puede ver la prematrícula.');
        $request->validate(['targetYear' => ['nullable', 'integer', 'min:2000', 'max:2100']]);

        $group->loadMissing(['academicYear', 'gradeLevel', 'institution', 'director:id,name']);
        $targetYear = (int) ($request->input('targetYear') ?: $group->academicYear->year + 1);

        $students = Student::whereHas('studentGroups', fn ($q) => $q->where('group_id', $group->id)->where('status', 'activo'))
            ->orderBy('last_name')->orderBy('first_name')->get();
        $existing = PreEnrollment::whereIn('student_id', $students->pluck('id'))->where('target_year', $targetYear)->get()->keyBy('student_id');

        // Grado sugerido: el siguiente al del grupo (por número). Once no tiene siguiente.
        $current = $group->gradeLevel;
        $suggested = $current && $current->level !== null
            ? GradeLevel::where('institution_id', $group->institution_id)->where('level', $current->level + 1)->first()
            : null;

        return [$targetYear, $students, $existing, $suggested];
    }
}
