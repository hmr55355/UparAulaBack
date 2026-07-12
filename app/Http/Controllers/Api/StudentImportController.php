<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Student;
use App\Models\StudentGroup;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;

class StudentImportController extends Controller
{
    /**
     * Importar/Exportar (módulo 18): sube un .xlsx con columnas
     * Apellidos | Nombres | Tipo documento | Número documento | Email (fila 1 = encabezados)
     * y matricula cada fila válida en el grupo indicado.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'file' => ['required', 'file', 'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel', 'max:5120'],
        ]);

        $group = Group::findOrFail($validated['group_id']);
        $this->authorize('manageAcademics', $group->institution);

        $spreadsheet = IOFactory::load($request->file('file')->getRealPath());
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

        $created = 0;
        $skipped = 0;
        $errors = [];

        foreach (array_slice($rows, 1) as $index => $row) {
            [$lastName, $firstName, $documentType, $documentNumber, $email] = array_pad($row, 5, null);

            if (empty($lastName) || empty($firstName)) {
                $skipped++;
                continue;
            }

            try {
                $student = Student::create([
                    'institution_id' => $group->institution_id,
                    'last_name' => $lastName,
                    'first_name' => $firstName,
                    'document_type' => $documentType ?: null,
                    'document_number' => $documentNumber ?: null,
                    'email' => $email ?: null,
                ]);

                StudentGroup::create([
                    'student_id' => $student->id,
                    'group_id' => $group->id,
                    'academic_year_id' => $group->academic_year_id,
                    'enrollment_date' => now()->toDateString(),
                    'status' => 'activo',
                ]);

                $created++;
            } catch (\Throwable $e) {
                $errors[] = 'Fila '.($index + 2).': '.$e->getMessage();
            }
        }

        return response()->json(['created' => $created, 'skipped' => $skipped, 'errors' => $errors]);
    }
}
