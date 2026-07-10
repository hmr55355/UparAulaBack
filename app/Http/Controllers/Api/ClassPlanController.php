<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassPlan;
use App\Models\GroupSubject;
use App\Models\Period;
use Illuminate\Http\Request;

class ClassPlanController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'groupSubjectId' => ['required', 'integer', 'exists:group_subjects,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $groupSubject = GroupSubject::findOrFail($request->groupSubjectId);
        $this->authorize('view', $groupSubject);

        $plans = ClassPlan::where('group_subject_id', $groupSubject->id)
            ->whereDate('date', '>=', $request->from)
            ->whereDate('date', '<=', $request->to)
            ->orderByDesc('date')
            ->get();

        return response()->json(['data' => $plans]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'group_subject_id' => ['required', 'integer', 'exists:group_subjects,id'],
            'date' => ['required', 'date'],
            'topic' => ['required', 'string', 'max:255'],
            'objectives' => ['nullable', 'string'],
            'activities' => ['nullable', 'string'],
            'resources' => ['nullable', 'string'],
            'class_schedule_id' => ['nullable', 'integer', 'exists:class_schedules,id'],
        ]);

        $groupSubject = GroupSubject::findOrFail($validated['group_subject_id']);
        $this->authorize('update', $groupSubject);

        $plan = ClassPlan::create([
            ...$validated,
            'period_id' => $this->resolvePeriodForDate($groupSubject->academic_year_id, $validated['date'])?->id,
            'registered_by' => $request->user()->id,
            'status' => 'planeada',
        ]);

        return response()->json(['data' => $plan], 201);
    }

    public function update(Request $request, ClassPlan $classPlan)
    {
        $this->authorize('update', $classPlan->groupSubject);

        $validated = $request->validate([
            'topic' => ['sometimes', 'string', 'max:255'],
            'objectives' => ['nullable', 'string'],
            'activities' => ['nullable', 'string'],
            'resources' => ['nullable', 'string'],
            'what_was_done' => ['nullable', 'string'],
            'pending_for_next_class' => ['nullable', 'string'],
            'attendance_note' => ['nullable', 'string', 'max:255'],
            'homework_assigned' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:planeada,ejecutada,pendiente,cancelada'],
        ]);

        $classPlan->update($validated);

        return response()->json(['data' => $classPlan->fresh()]);
    }

    public function updateStatus(Request $request, ClassPlan $classPlan)
    {
        $this->authorize('update', $classPlan->groupSubject);

        $validated = $request->validate([
            'status' => ['required', 'in:planeada,ejecutada,pendiente,cancelada'],
        ]);

        $classPlan->update($validated);

        return response()->json(['data' => $classPlan->fresh()]);
    }

    public function previous(Request $request)
    {
        $request->validate([
            'groupSubjectId' => ['required', 'integer', 'exists:group_subjects,id'],
        ]);

        $groupSubject = GroupSubject::findOrFail($request->groupSubjectId);
        $this->authorize('view', $groupSubject);

        $plan = ClassPlan::where('group_subject_id', $groupSubject->id)
            ->whereIn('status', ['ejecutada', 'pendiente'])
            ->orderByDesc('date')
            ->first();

        return response()->json(['data' => $plan]);
    }

    public function duplicateAsBase(Request $request, ClassPlan $classPlan)
    {
        $this->authorize('update', $classPlan->groupSubject);

        $today = now()->toDateString();

        $newPlan = ClassPlan::create([
            'group_subject_id' => $classPlan->group_subject_id,
            'period_id' => $this->resolvePeriodForDate($classPlan->groupSubject->academic_year_id, $today)?->id,
            'class_schedule_id' => null,
            'registered_by' => $request->user()->id,
            'date' => $today,
            'topic' => $classPlan->pending_for_next_class ?: $classPlan->topic,
            'objectives' => null,
            'activities' => null,
            'status' => 'planeada',
        ]);

        return response()->json(['data' => $newPlan], 201);
    }

    private function resolvePeriodForDate(int $academicYearId, string $date): ?Period
    {
        return Period::where('academic_year_id', $academicYearId)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first()
            ?? Period::where('academic_year_id', $academicYearId)->where('is_active', true)->first();
    }
}
