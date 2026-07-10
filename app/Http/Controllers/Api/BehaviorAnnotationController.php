<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BehaviorAnnotation;
use App\Models\Group;
use App\Models\Student;
use Illuminate\Http\Request;

class BehaviorAnnotationController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'groupId' => ['required', 'integer', 'exists:groups,id'],
            'type' => ['sometimes', 'in:positiva,negativa,informativa,acuerdo'],
            'category' => ['sometimes', 'string'],
            'studentId' => ['sometimes', 'integer'],
            'date' => ['sometimes', 'date'],
        ]);

        $group = Group::findOrFail($request->groupId);
        $this->authorizeGroupAccess($request->user(), $group);

        $annotations = BehaviorAnnotation::where('group_id', $group->id)
            ->when($request->type, fn ($q, $type) => $q->where('type', $type))
            ->when($request->category, fn ($q, $category) => $q->where('category', $category))
            ->when($request->studentId, fn ($q, $studentId) => $q->where('student_id', $studentId))
            ->when($request->date, fn ($q, $date) => $q->whereDate('date', $date))
            ->with(['student:id,first_name,last_name', 'registeredBy:id,name'])
            ->orderByDesc('date')
            ->get()
            ->map(fn (BehaviorAnnotation $a) => $this->withTeacherName($a));

        return response()->json(['data' => $annotations]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);

        $student = Student::findOrFail($validated['student_id']);
        $group = Group::findOrFail($validated['group_id']);
        abort_unless($student->canBeAccessedBy($request->user()), 403);
        $this->authorizeGroupAccess($request->user(), $group);

        $annotation = BehaviorAnnotation::create([
            ...$validated,
            'registered_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $annotation->load('student:id,first_name,last_name')], 201);
    }

    public function show(Request $request, BehaviorAnnotation $behaviorAnnotation)
    {
        abort_unless($behaviorAnnotation->student->canBeAccessedBy($request->user()), 403);

        $behaviorAnnotation->load(['student', 'registeredBy:id,name']);

        return response()->json(['data' => $this->withTeacherName($behaviorAnnotation)]);
    }

    public function update(Request $request, BehaviorAnnotation $behaviorAnnotation)
    {
        abort_unless($behaviorAnnotation->student->canBeAccessedBy($request->user()), 403);

        $validated = $request->validate([
            'type' => ['sometimes', 'in:positiva,negativa,informativa,acuerdo'],
            'category' => ['sometimes', 'in:academico,convivencia,puntualidad,presentacion,participacion,actitud,otro'],
            'title' => ['sometimes', 'string', 'max:80'],
            'description' => ['sometimes', 'string'],
            'action_taken' => ['nullable', 'string'],
            'requires_parent_contact' => ['sometimes', 'boolean'],
        ]);

        $behaviorAnnotation->update($validated);

        return response()->json(['data' => $behaviorAnnotation]);
    }

    public function destroy(Request $request, BehaviorAnnotation $behaviorAnnotation)
    {
        abort_unless($behaviorAnnotation->student->canBeAccessedBy($request->user()), 403);

        $behaviorAnnotation->delete();

        return response()->json(['message' => 'Anotación eliminada.']);
    }

    public function markContacted(Request $request, BehaviorAnnotation $behaviorAnnotation)
    {
        abort_unless($behaviorAnnotation->student->canBeAccessedBy($request->user()), 403);

        $behaviorAnnotation->update([
            'parent_contacted' => true,
            'parent_contact_date' => now()->toDateString(),
        ]);

        return response()->json(['data' => $behaviorAnnotation]);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'group_subject_id' => ['nullable', 'integer', 'exists:group_subjects,id'],
            'date' => ['required', 'date'],
            'type' => ['required', 'in:positiva,negativa,informativa,acuerdo'],
            'category' => ['required', 'in:academico,convivencia,puntualidad,presentacion,participacion,actitud,otro'],
            'title' => ['required', 'string', 'max:80'],
            'description' => ['required', 'string'],
            'action_taken' => ['nullable', 'string'],
            'requires_parent_contact' => ['sometimes', 'boolean'],
        ]);
    }

    private function authorizeGroupAccess(\App\Models\User $user, Group $group): void
    {
        $teachesGroup = $group->groupSubjects()->where('user_id', $user->id)->where('is_active', true)->exists();
        abort_unless($teachesGroup || $user->isAdminOf($group->institution_id), 403);
    }

    /**
     * Serializes with a distinct `teacher_name` field instead of the loaded
     * `registeredBy` relation — that relation's snake_cased JSON key
     * ("registered_by") collides with the plain `registered_by` FK column, so
     * eager-loading it would silently overwrite the numeric id in the response.
     */
    private function withTeacherName(BehaviorAnnotation $annotation): array
    {
        $teacherName = $annotation->registeredBy?->name;

        // Drop the loaded relation before serializing: its snake_cased JSON key
        // ("registered_by") would otherwise silently overwrite the plain
        // `registered_by` FK column in the array below.
        $annotation->unsetRelation('registeredBy');

        $array = $annotation->toArray();
        $array['teacher_name'] = $teacherName;

        return $array;
    }
}
