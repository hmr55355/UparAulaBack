<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GradeSection;
use App\Models\GradeTemplate;
use App\Models\GroupSubject;
use App\Models\Period;
use App\Services\GradeCalculatorService;
use Illuminate\Http\Request;

class GradeTemplateController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['institutionId' => ['required', 'integer', 'exists:institutions,id']]);

        $templates = GradeTemplate::where('institution_id', $request->institutionId)
            ->where(fn ($q) => $q->where('user_id', $request->user()->id)->orWhere('is_shared', true))
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $templates]);
    }

    public function store(Request $request, GradeCalculatorService $calculator)
    {
        $validated = $request->validate([
            'group_subject_id' => ['required', 'integer', 'exists:group_subjects,id'],
            'period_id' => ['required', 'integer', 'exists:periods,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_shared' => ['sometimes', 'boolean'],
        ]);

        $groupSubject = GroupSubject::findOrFail($validated['group_subject_id']);
        $this->authorize('view', $groupSubject);
        $period = Period::findOrFail($validated['period_id']);

        $sectionsConfig = $calculator->buildSectionsConfig($groupSubject, $period);

        if (empty($sectionsConfig['sections'])) {
            return response()->json(['message' => 'Esta planilla no tiene secciones configuradas todavía.'], 422);
        }

        $template = GradeTemplate::create([
            'user_id' => $request->user()->id,
            'institution_id' => $groupSubject->institution_id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_shared' => $validated['is_shared'] ?? false,
            'sections_config' => $sectionsConfig,
        ]);

        return response()->json(['data' => $template], 201);
    }

    public function update(Request $request, GradeTemplate $gradeTemplate)
    {
        abort_unless($gradeTemplate->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_shared' => ['sometimes', 'boolean'],
        ]);

        $gradeTemplate->update($validated);

        return response()->json(['data' => $gradeTemplate]);
    }

    public function destroy(Request $request, GradeTemplate $gradeTemplate)
    {
        abort_unless($gradeTemplate->user_id === $request->user()->id, 403);

        $gradeTemplate->delete();

        return response()->json(['message' => 'Plantilla eliminada.']);
    }

    public function apply(Request $request, GradeTemplate $gradeTemplate, GradeCalculatorService $calculator)
    {
        $validated = $request->validate([
            'group_subject_id' => ['required', 'integer', 'exists:group_subjects,id'],
            'period_id' => ['required', 'integer', 'exists:periods,id'],
        ]);

        $groupSubject = GroupSubject::findOrFail($validated['group_subject_id']);
        $this->authorize('update', $groupSubject);

        abort_unless(
            $gradeTemplate->is_shared || $gradeTemplate->user_id === $request->user()->id,
            403,
            'No tienes acceso a esta plantilla.'
        );
        abort_unless(
            $gradeTemplate->institution_id === null || $gradeTemplate->institution_id === $groupSubject->institution_id,
            403,
            'Esta plantilla pertenece a otra institución.'
        );

        $period = Period::findOrFail($validated['period_id']);
        abort_if($period->is_closed, 422, 'El período está cerrado.');

        $alreadyConfigured = GradeSection::where('group_subject_id', $groupSubject->id)
            ->where('period_id', $period->id)
            ->exists();
        abort_if($alreadyConfigured, 422, 'Esta planilla ya tiene secciones configuradas. Elimínalas primero para aplicar una plantilla nueva.');

        $sections = $calculator->applyTemplate($gradeTemplate, $groupSubject, $period);

        return response()->json(['data' => $sections]);
    }
}
