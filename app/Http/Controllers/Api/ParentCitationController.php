<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\ParentCitation;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;

class ParentCitationController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'groupId' => ['required', 'integer', 'exists:groups,id'],
            'status' => ['sometimes', 'in:pendiente,notificado,confirmado,realizado,no_asistio,reprogramado'],
            'citationType' => ['sometimes', 'string'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $group = Group::findOrFail($request->groupId);
        $this->authorizeGroupAccess($request->user(), $group);

        $citations = ParentCitation::where('group_id', $group->id)
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->citationType, fn ($q, $type) => $q->where('citation_type', $type))
            ->when($request->from, fn ($q, $from) => $q->whereDate('scheduled_date', '>=', $from))
            ->when($request->to, fn ($q, $to) => $q->whereDate('scheduled_date', '<=', $to))
            ->with(['student:id,first_name,last_name', 'parent:id,first_name,last_name,phone,relationship'])
            ->orderByDesc('scheduled_date')
            ->get();

        return response()->json(['data' => $citations]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'parent_id' => ['nullable', 'integer', 'exists:parents,id'],
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'behavior_annotation_id' => ['nullable', 'integer', 'exists:behavior_annotations,id'],
            'citation_type' => ['required', 'in:academica,comportamiento,seguimiento,entrega_boletin,general'],
            'reason' => ['required', 'string'],
            'scheduled_date' => ['nullable', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'notification_method' => ['nullable', 'in:celular,whatsapp,correo,agenda,otro'],
        ]);

        $student = Student::findOrFail($validated['student_id']);
        $group = Group::findOrFail($validated['group_id']);
        abort_unless($student->canBeAccessedBy($request->user()), 403);
        $this->authorizeGroupAccess($request->user(), $group);

        $citation = ParentCitation::create([
            ...$validated,
            'registered_by' => $request->user()->id,
            'status' => 'pendiente',
        ]);

        return response()->json(['data' => $citation->load('student:id,first_name,last_name', 'parent')], 201);
    }

    public function show(Request $request, ParentCitation $citation)
    {
        abort_unless($citation->student->canBeAccessedBy($request->user()), 403);

        $citation->load(['student', 'parent', 'registeredBy:id,name', 'behaviorAnnotation']);
        $teacherName = $citation->registeredBy?->name;

        // Same fix as BehaviorAnnotationController::withTeacherName(): the loaded
        // relation's snake_cased key would otherwise overwrite the plain
        // `registered_by` FK column when serialized.
        $citation->unsetRelation('registeredBy');
        $data = $citation->toArray();
        $data['teacher_name'] = $teacherName;

        return response()->json(['data' => $data]);
    }

    public function update(Request $request, ParentCitation $citation)
    {
        abort_unless($citation->student->canBeAccessedBy($request->user()), 403);

        $validated = $request->validate([
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:parents,id'],
            'citation_type' => ['sometimes', 'in:academica,comportamiento,seguimiento,entrega_boletin,general'],
            'reason' => ['sometimes', 'string'],
            'scheduled_date' => ['sometimes', 'nullable', 'date'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notification_method' => ['sometimes', 'nullable', 'in:celular,whatsapp,correo,agenda,otro'],
        ]);

        $citation->update($validated);

        return response()->json(['data' => $citation]);
    }

    public function updateStatus(Request $request, ParentCitation $citation)
    {
        abort_unless($citation->student->canBeAccessedBy($request->user()), 403);

        $validated = $request->validate([
            'status' => ['required', 'in:pendiente,notificado,confirmado,realizado,no_asistio,reprogramado'],
            'outcome' => ['required_if:status,realizado', 'nullable', 'string'],
            'commitments' => ['nullable', 'string'],
            'follow_up_date' => ['nullable', 'date'],
        ]);

        if ($validated['status'] === 'notificado') {
            $validated['notification_date'] = now();
        }

        $citation->update($validated);

        return response()->json(['data' => $citation]);
    }

    public function destroy(Request $request, ParentCitation $citation)
    {
        abort_unless($citation->student->canBeAccessedBy($request->user()), 403);

        $citation->delete();

        return response()->json(['message' => 'Citación eliminada.']);
    }

    private function authorizeGroupAccess(User $user, Group $group): void
    {
        $teachesGroup = $group->groupSubjects()->where('user_id', $user->id)->where('is_active', true)->exists();
        abort_unless($teachesGroup || $user->isAdminOf($group->institution_id), 403);
    }
}
