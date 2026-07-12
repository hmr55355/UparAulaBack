<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\Group;
use App\Models\GroupSubject;
use App\Models\PeriodFinal;
use App\Models\Student;
use Illuminate\Http\Request;

class ExportController extends Controller
{
    /**
     * Importar/Exportar (módulo 18): exportar datos del año en JSON. Síncrono
     * (no pasa por la cola de Reportes de la Fase 10) porque es un simple
     * dump, sin generación de archivo Excel/PDF pesado.
     */
    public function yearData(Request $request, AcademicYear $academicYear)
    {
        $this->authorize('manageAcademics', $academicYear->institution);

        $groups = Group::where('academic_year_id', $academicYear->id)->get(['id', 'name', 'grade_level']);
        $groupSubjects = GroupSubject::where('academic_year_id', $academicYear->id)
            ->with('subject:id,name', 'teacher:id,name')
            ->get(['id', 'group_id', 'subject_id', 'user_id']);
        $students = Student::whereHas(
            'studentGroups',
            fn ($q) => $q->where('academic_year_id', $academicYear->id)
        )->get(['id', 'institution_id', 'first_name', 'last_name', 'document_number']);
        $periodFinals = PeriodFinal::whereIn('group_subject_id', $groupSubjects->pluck('id'))->get();
        $attendanceRecords = AttendanceRecord::whereIn('group_subject_id', $groupSubjects->pluck('id'))->get();

        $data = [
            'academic_year' => $academicYear,
            'groups' => $groups,
            'group_subjects' => $groupSubjects,
            'students' => $students,
            'period_finals' => $periodFinals,
            'attendance_records' => $attendanceRecords,
        ];

        $filename = "uparaula-{$academicYear->year}.json";

        return response()->json($data)
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }
}
