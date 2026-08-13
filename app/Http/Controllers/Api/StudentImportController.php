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
     * Columna destino => encabezados aceptados (ya normalizados: minúsculas, sin tildes).
     * Cubre tanto la plantilla original de la app como el export oficial de matrícula
     * (SIMAT), que trae más columnas, en otro orden, con nombres tipo "Estudiante - Documento".
     */
    private const HEADER_ALIASES = [
        'last_name' => ['apellidos'],
        'first_name' => ['nombres'],
        'document_type' => ['tipo documento', 'tipo de documento', 'estudiante - tipo de documento'],
        'document_number' => ['numero documento', 'numero de documento', 'documento', 'estudiante - documento'],
        'email' => ['email', 'correo', 'correo electronico'],
    ];

    /**
     * Texto de tipo de documento (tal cual lo exportan sistemas oficiales) => código corto
     * que acepta la columna `document_type` (enum TI/CC/CE/PA/PPT).
     */
    private const DOCUMENT_TYPE_VALUES = [
        'ti' => 'TI', 'tarjeta de identidad' => 'TI',
        'cc' => 'CC', 'cedula de ciudadania' => 'CC',
        'ce' => 'CE', 'cedula de extranjeria' => 'CE',
        'pa' => 'PA', 'pasaporte' => 'PA',
        'ppt' => 'PPT', 'permiso por proteccion temporal' => 'PPT',
    ];

    /**
     * Importar/Exportar (módulo 18): sube un .xlsx (fila 1 = encabezados) y matricula
     * cada fila válida en el grupo indicado. Las columnas se ubican por nombre de
     * encabezado, no por posición, para aceptar tanto la plantilla simple de la app
     * como un export real de matrícula con columnas de sobra en otro orden.
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

        $headerRow = array_map(fn ($h) => self::normalize((string) $h), $rows[0] ?? []);
        $columns = [];
        foreach (self::HEADER_ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $colIndex = array_search($alias, $headerRow, true);
                if ($colIndex !== false) {
                    $columns[$field] = $colIndex;
                    break;
                }
            }
        }

        if (! isset($columns['last_name']) || ! isset($columns['first_name'])) {
            return response()->json([
                'message' => 'No encontramos las columnas "Apellidos" y "Nombres" en la fila 1 del archivo.',
            ], 422);
        }

        $created = 0;
        $skipped = 0;
        $errors = [];

        foreach (array_slice($rows, 1) as $index => $row) {
            $lastName = $row[$columns['last_name']] ?? null;
            $firstName = $row[$columns['first_name']] ?? null;
            $documentTypeRaw = isset($columns['document_type']) ? ($row[$columns['document_type']] ?? null) : null;
            $documentNumber = isset($columns['document_number']) ? ($row[$columns['document_number']] ?? null) : null;
            $email = isset($columns['email']) ? ($row[$columns['email']] ?? null) : null;

            if (empty($lastName) || empty($firstName)) {
                $skipped++;
                continue;
            }

            $documentType = null;
            if (! empty($documentTypeRaw)) {
                $documentType = self::DOCUMENT_TYPE_VALUES[self::normalize((string) $documentTypeRaw)] ?? null;
                if ($documentType === null) {
                    $errors[] = 'Fila '.($index + 2).": tipo de documento \"{$documentTypeRaw}\" no reconocido, se dejó el valor por defecto.";
                }
            }

            try {
                $student = Student::create([
                    'institution_id' => $group->institution_id,
                    'last_name' => $lastName,
                    'first_name' => $firstName,
                    'document_type' => $documentType ?? 'TI',
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

    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u',
        ]);

        return preg_replace('/\s+/', ' ', $value);
    }
}
