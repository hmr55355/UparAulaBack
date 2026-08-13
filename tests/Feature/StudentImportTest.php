<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Group;
use App\Models\Institution;
use App\Models\InstitutionTeacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class StudentImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Institution $institution;

    private Group $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->institution = Institution::create([
            'name' => 'Colegio Test', 'city' => 'Cali', 'department' => 'Valle',
            'min_passing_grade' => 6.0, 'created_by' => $this->admin->id,
        ]);
        $academicYear = AcademicYear::create([
            'institution_id' => $this->institution->id, 'year' => 2026,
            'start_date' => '2026-01-20', 'end_date' => '2026-11-28',
        ]);
        $this->group = Group::create([
            'institution_id' => $this->institution->id, 'academic_year_id' => $academicYear->id,
            'name' => '10-1', 'grade_level' => '10',
        ]);
        InstitutionTeacher::create([
            'institution_id' => $this->institution->id, 'user_id' => $this->admin->id, 'role' => 'admin', 'status' => 'active',
        ]);
    }

    private function buildXlsx(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $colIndex => $value) {
                $sheet->setCellValue([$colIndex + 1, $rowIndex + 1], $value);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'estudiantes.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_importing_a_valid_file_creates_students_and_enrollments(): void
    {
        $file = $this->buildXlsx([
            ['Apellidos', 'Nombres', 'Tipo documento', 'Número documento', 'Email'],
            ['Gómez', 'Ana', 'TI', '1001', 'ana@example.com'],
            ['Ramírez', 'Luis', 'TI', '1002', ''],
            ['Torres', '', 'TI', '1003', ''], // sin nombre, se debe saltar
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/students/import', [
            'group_id' => $this->group->id,
            'file' => $file,
        ]);

        $response->assertOk();
        $this->assertEquals(2, $response->json('created'));
        $this->assertEquals(1, $response->json('skipped'));
        $this->assertDatabaseHas('students', ['first_name' => 'Ana', 'last_name' => 'Gómez']);
        $this->assertDatabaseCount('student_groups', 2);
    }

    public function test_importing_a_simat_style_export_matches_columns_by_header(): void
    {
        $file = $this->buildXlsx([
            ['Nombre del Grado', 'Grado', 'Grupo', 'Estado Matrícula', 'Fecha de matrícula', 'Apellidos', 'Nombres', 'Estudiante - Documento', 'Estudiante - Tipo de documento', 'Acudiente - Teléfono celular', 'Estudiante - Resumen de discapacidades'],
            ['Décimo', 10, '3MMGC', 'Activo', '11 de Diciembre de 2025', 'Alvarado Niño', 'Jesus Adrian', 1067609751, 'Tarjeta de Identidad', 3153848628, 'Ninguna'],
            ['Décimo', 10, '3MMGC', 'Activo', '22 de Diciembre de 2025', 'Calderon Arguello', 'Moises David', 5760012, 'Permiso por protección temporal', 3215369529, 'Ninguna'],
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/students/import', [
            'group_id' => $this->group->id,
            'file' => $file,
        ]);

        $response->assertOk();
        $this->assertEquals(2, $response->json('created'));
        $this->assertDatabaseHas('students', [
            'first_name' => 'Jesus Adrian', 'last_name' => 'Alvarado Niño', 'document_type' => 'TI', 'document_number' => '1067609751',
        ]);
        $this->assertDatabaseHas('students', [
            'first_name' => 'Moises David', 'last_name' => 'Calderon Arguello', 'document_type' => 'PPT',
        ]);
    }

    public function test_importing_a_file_without_recognizable_headers_returns_a_clear_error(): void
    {
        $file = $this->buildXlsx([
            ['Columna A', 'Columna B'],
            ['algo', 'algo'],
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/students/import', [
            'group_id' => $this->group->id,
            'file' => $file,
        ]);

        $response->assertStatus(422);
    }

    public function test_a_non_admin_teacher_is_forbidden_from_importing(): void
    {
        $teacher = User::factory()->create();
        InstitutionTeacher::create([
            'institution_id' => $this->institution->id, 'user_id' => $teacher->id, 'role' => 'teacher', 'status' => 'active',
        ]);
        $file = $this->buildXlsx([['Apellidos', 'Nombres'], ['Gómez', 'Ana']]);

        $this->actingAs($teacher, 'sanctum')->postJson('/api/students/import', [
            'group_id' => $this->group->id,
            'file' => $file,
        ])->assertForbidden();
    }
}
