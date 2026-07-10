<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ParentGuardian;
use App\Models\Student;
use Illuminate\Http\Request;

class ParentController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['studentId' => ['required', 'integer', 'exists:students,id']]);

        $student = Student::findOrFail($request->studentId);
        abort_unless($student->canBeAccessedBy($request->user()), 403);

        return response()->json(['data' => $student->parents()->get()]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'relationship' => ['required', 'in:padre,madre,abuelo,abuela,tio,tia,hermano,hermana,acudiente_otro'],
            'phone' => ['required', 'string', 'max:30'],
            'phone_alt' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        $student = Student::findOrFail($validated['student_id']);
        abort_unless($student->canBeAccessedBy($request->user()), 403);

        $parent = ParentGuardian::create([
            'institution_id' => $student->institution_id,
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'relationship' => $validated['relationship'],
            'phone' => $validated['phone'],
            'phone_alt' => $validated['phone_alt'] ?? null,
            'email' => $validated['email'] ?? null,
        ]);

        $student->parents()->attach($parent->id, ['is_primary' => $validated['is_primary'] ?? false]);

        return response()->json(['data' => $parent], 201);
    }

    public function update(Request $request, ParentGuardian $parent)
    {
        abort_unless(
            $parent->students->contains(fn ($student) => $student->canBeAccessedBy($request->user())),
            403
        );

        $validated = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'relationship' => ['sometimes', 'in:padre,madre,abuelo,abuela,tio,tia,hermano,hermana,acudiente_otro'],
            'phone' => ['sometimes', 'string', 'max:30'],
            'phone_alt' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email'],
        ]);

        $parent->update($validated);

        return response()->json(['data' => $parent]);
    }
}
