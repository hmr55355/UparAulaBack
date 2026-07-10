<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\StudentObservation;
use Illuminate\Http\Request;

class StudentObservationController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'studentId' => ['required', 'integer', 'exists:students,id'],
            'periodId' => ['sometimes', 'integer'],
        ]);

        $student = Student::findOrFail($request->studentId);
        abort_unless($student->canBeAccessedBy($request->user()), 403);

        $observations = StudentObservation::where('student_id', $student->id)
            ->when($request->periodId, fn ($q, $periodId) => $q->where('period_id', $periodId))
            ->where(function ($q) use ($request) {
                // Nota del prompt: is_private=true solo es visible para quien la registró.
                $q->where('is_private', false)->orWhere('registered_by', $request->user()->id);
            })
            ->with('registeredBy:id,name')
            ->orderByDesc('date')
            ->get()
            ->map(fn (StudentObservation $o) => $this->withTeacherName($o));

        return response()->json(['data' => $observations]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'period_id' => ['nullable', 'integer', 'exists:periods,id'],
            'date' => ['required', 'date'],
            'type' => ['required', 'in:academica,comportamental,familiar,salud,seguimiento,logro,otro'],
            'content' => ['required', 'string'],
            'is_private' => ['sometimes', 'boolean'],
        ]);

        $student = Student::findOrFail($validated['student_id']);
        abort_unless($student->canBeAccessedBy($request->user()), 403);

        $observation = StudentObservation::create([
            ...$validated,
            'registered_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $observation], 201);
    }

    public function update(Request $request, StudentObservation $studentObservation)
    {
        abort_unless(
            $studentObservation->registered_by === $request->user()->id
                || $studentObservation->student->canBeAccessedBy($request->user()),
            403
        );
        // Solo el autor puede editar una observación privada.
        abort_if($studentObservation->is_private && $studentObservation->registered_by !== $request->user()->id, 403);

        $validated = $request->validate([
            'type' => ['sometimes', 'in:academica,comportamental,familiar,salud,seguimiento,logro,otro'],
            'content' => ['sometimes', 'string'],
            'is_private' => ['sometimes', 'boolean'],
        ]);

        $studentObservation->update($validated);

        return response()->json(['data' => $studentObservation]);
    }

    public function destroy(Request $request, StudentObservation $studentObservation)
    {
        abort_unless($studentObservation->registered_by === $request->user()->id, 403);

        $studentObservation->delete();

        return response()->json(['message' => 'Observación eliminada.']);
    }

    private function withTeacherName(StudentObservation $observation): array
    {
        $teacherName = $observation->registeredBy?->name;
        $observation->unsetRelation('registeredBy');

        $array = $observation->toArray();
        $array['teacher_name'] = $teacherName;

        return $array;
    }
}
