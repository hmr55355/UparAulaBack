<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GroupSubject;
use Illuminate\Http\Request;

class GroupSubjectController extends Controller
{
    public function index(Request $request)
    {
        $query = GroupSubject::with(['group', 'subject', 'teacher:id,name'])
            ->where('is_active', true);

        if ($request->query('groupId')) {
            $query->where('group_id', $request->query('groupId'));
        }

        if ($request->query('periodId')) {
            // periods belong to an academic year; group_subjects are scoped per academic year,
            // so we resolve the academic_year_id from the period to filter consistently.
            $period = \App\Models\Period::find($request->query('periodId'));
            if ($period) {
                $query->where('academic_year_id', $period->academic_year_id);
            }
        }

        if (! $request->user()->isAdminOf($request->query('institutionId') ?? 0)) {
            $query->where('user_id', $request->user()->id);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'institution_id' => ['required', 'integer', 'exists:institutions,id'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
        ]);

        $this->authorize('manageAcademics', \App\Models\Institution::findOrFail($validated['institution_id']));

        $groupSubject = GroupSubject::create($validated);

        return response()->json(['data' => $groupSubject], 201);
    }

    public function update(Request $request, GroupSubject $groupSubject)
    {
        $this->authorize('update', $groupSubject);

        $validated = $request->validate([
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $groupSubject->update($validated);

        return response()->json(['data' => $groupSubject]);
    }
}
