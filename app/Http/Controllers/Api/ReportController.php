<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateReportJob;
use App\Models\Group;
use App\Models\GroupSubject;
use App\Models\Institution;
use App\Models\Report;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReportController extends Controller
{
    public function gradeSheet(Request $request)
    {
        $validated = $request->validate([
            'group_subject_id' => ['required', 'integer', 'exists:group_subjects,id'],
            'period_id' => ['required', 'integer', 'exists:periods,id'],
            'format' => ['required', 'in:excel,pdf'],
        ]);

        $groupSubject = GroupSubject::findOrFail($validated['group_subject_id']);
        $this->authorize('view', $groupSubject);

        return $this->createAndDispatch($request, 'grade_sheet', $groupSubject->institution_id, $validated);
    }

    public function studentBulletin(Request $request)
    {
        $validated = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'period_id' => ['required', 'integer', 'exists:periods,id'],
        ]);

        $student = Student::findOrFail($validated['student_id']);
        // Quien le dicta, el director de su grupo o un administrador. Un docente que no
        // dirige el grupo recibe el boletín solo con sus materias (ver el servicio).
        $directs = $student->studentGroups()->where('status', 'activo')
            ->whereHas('group', fn ($q) => $q->where('director_id', $request->user()->id))->exists();
        abort_unless($student->canBeAccessedBy($request->user()) || $directs, 403, 'Este estudiante no es de tus grupos.');

        $validated['format'] = 'pdf';

        return $this->createAndDispatch($request, 'student_bulletin', $student->institution_id, $validated);
    }

    public function attendanceSheet(Request $request)
    {
        $validated = $request->validate([
            'group_subject_id' => ['required', 'integer', 'exists:group_subjects,id'],
            'period_id' => ['required', 'integer', 'exists:periods,id'],
            'format' => ['required', 'in:excel,pdf'],
        ]);

        $groupSubject = GroupSubject::findOrFail($validated['group_subject_id']);
        $this->authorize('view', $groupSubject);

        return $this->createAndDispatch($request, 'attendance_sheet', $groupSubject->institution_id, $validated);
    }

    public function behaviorCitations(Request $request)
    {
        $validated = $request->validate([
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'period_id' => ['required', 'integer', 'exists:periods,id'],
            'format' => ['required', 'in:excel,pdf'],
        ]);

        $group = Group::findOrFail($validated['group_id']);
        // Igual que las pantallas de Comportamiento y Citaciones: quien dicta en el grupo,
        // su director o un administrador.
        $user = $request->user();
        $teachesGroup = $group->groupSubjects()->where('user_id', $user->id)->where('is_active', true)->exists();
        abort_unless($teachesGroup || $group->canBeOverseenBy($user), 403, 'No dictas en este grupo.');

        return $this->createAndDispatch($request, 'behavior_citations', $group->institution_id, $validated);
    }

    public function academicRisk(Request $request)
    {
        $validated = $request->validate([
            'institution_id' => ['required', 'integer', 'exists:institutions,id'],
            'period_id' => ['required', 'integer', 'exists:periods,id'],
            'format' => ['required', 'in:excel,pdf'],
        ]);

        $institution = Institution::findOrFail($validated['institution_id']);
        // Trae las definitivas de todos los docentes: solo administradores.
        $this->authorize('manageAcademics', $institution);

        return $this->createAndDispatch($request, 'academic_risk', $institution->id, $validated);
    }

    public function show(Request $request, Report $report)
    {
        abort_unless($report->user_id === $request->user()->id, 403);

        return response()->json(['data' => $report->only(['id', 'type', 'format', 'status', 'error_message', 'created_at'])]);
    }

    public function download(Request $request, Report $report)
    {
        abort_unless($report->user_id === $request->user()->id, 403);
        abort_if($report->status !== 'completed', 409, 'El reporte todavía no está listo.');

        $extension = pathinfo($report->file_path, PATHINFO_EXTENSION);
        $filename = $report->type.'-'.$report->id.'.'.$extension;

        return Storage::disk('local')->download($report->file_path, $filename);
    }

    private function createAndDispatch(Request $request, string $type, int $institutionId, array $params)
    {
        $report = Report::create([
            'user_id' => $request->user()->id,
            'institution_id' => $institutionId,
            'type' => $type,
            'format' => $params['format'],
            'params' => $params,
            'status' => 'pending',
        ]);

        // En la misma petición, sin cola: en el hosting no hay un `queue:work` corriendo y
        // el reporte se quedaba "pendiente" para siempre. Un reporte tarda 1–2 segundos.
        GenerateReportJob::dispatchSync($report->id);

        return response()->json(['data' => ['id' => $report->id]], 202);
    }
}
