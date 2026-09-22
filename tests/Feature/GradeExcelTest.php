<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\GradeColumn;
use App\Models\GradeSection;
use App\Models\Group;
use App\Models\GroupSubject;
use App\Models\Institution;
use App\Models\InstitutionTeacher;
use App\Models\Period;
use App\Models\PeriodFinal;
use App\Models\Student;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class GradeExcelTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private GroupSubject $groupSubject;

    private Period $period;

    private Student $ana;

    private Student $luis;

    private GradeColumn $ev1;

    private GradeColumn $ev2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $institution = Institution::create([
            'name' => 'Colegio Test', 'city' => 'Cali', 'department' => 'Valle',
            'min_passing_grade' => 6.0, 'created_by' => $this->teacher->id,
        ]);
        $academicYear = AcademicYear::create([
            'institution_id' => $institution->id, 'year' => 2026,
            'start_date' => '2026-01-20', 'end_date' => '2026-11-28',
        ]);
        $this->period = Period::create([
            'academic_year_id' => $academicYear->id, 'number' => 1, 'name' => 'Primer Período',
            'start_date' => '2026-01-20', 'end_date' => '2026-03-20', 'is_active' => true,
        ]);
        $group = Group::create([
            'institution_id' => $institution->id, 'academic_year_id' => $academicYear->id,
            'name' => '10-1', 'grade_level' => '10',
        ]);
        $subject = Subject::create(['institution_id' => $institution->id, 'name' => 'Matemáticas']);
        $this->groupSubject = GroupSubject::create([
            'group_id' => $group->id, 'subject_id' => $subject->id, 'user_id' => $this->teacher->id,
            'institution_id' => $institution->id, 'academic_year_id' => $academicYear->id,
        ]);
        InstitutionTeacher::create([
            'institution_id' => $institution->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'status' => 'active',
        ]);

        foreach ([['Ana', 'Gómez'], ['Luis', 'Ramírez']] as [$first, $last]) {
            $student = Student::create(['institution_id' => $institution->id, 'first_name' => $first, 'last_name' => $last]);
            StudentGroup::create([
                'student_id' => $student->id, 'group_id' => $group->id, 'academic_year_id' => $academicYear->id,
                'enrollment_date' => '2026-01-20', 'status' => 'activo',
            ]);
            $first === 'Ana' ? $this->ana = $student : $this->luis = $student;
        }

        $section = GradeSection::create([
            'group_subject_id' => $this->groupSubject->id, 'period_id' => $this->period->id,
            'name' => 'Evaluaciones', 'weight' => 100, 'final_calculation' => 'weighted_avg',
        ]);
        $this->ev1 = GradeColumn::create([
            'grade_section_id' => $section->id, 'group_subject_id' => $this->groupSubject->id, 'period_id' => $this->period->id,
            'column_type' => 'manual', 'name' => 'ev1', 'short_name' => 'ev1', 'weight' => 50,
        ]);
        $this->ev2 = GradeColumn::create([
            'grade_section_id' => $section->id, 'group_subject_id' => $this->groupSubject->id, 'period_id' => $this->period->id,
            'column_type' => 'manual', 'name' => 'ev2', 'short_name' => 'ev2', 'weight' => 50,
        ]);
    }

    private function downloadTemplate(): Spreadsheet
    {
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->get("/api/grades/excel-template?groupSubjectId={$this->groupSubject->id}&periodId={$this->period->id}");
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        return IOFactory::load($path);
    }

    private function asUpload(Spreadsheet $spreadsheet): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'notas').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'notas.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_template_lists_students_and_manual_activities(): void
    {
        $sheet = $this->downloadTemplate()->getActiveSheet();

        $this->assertSame('student_id', $sheet->getCell('A1')->getValue());
        $this->assertSame("col:{$this->ev1->id}", $sheet->getCell('D1')->getValue());
        $this->assertSame("col:{$this->ev2->id}", $sheet->getCell('E1')->getValue());
        $this->assertSame('Gómez', $sheet->getCell('B3')->getValue());
        $this->assertSame('Ramírez', $sheet->getCell('B4')->getValue());
        $this->assertNull($sheet->getCell('D3')->getValue()); // plantilla vacía
    }

    public function test_filled_template_saves_grades_and_recalculates_finals(): void
    {
        $spreadsheet = $this->downloadTemplate();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('D3', 8);    // Ana ev1
        $sheet->setCellValue('E3', '9,0'); // Ana ev2, con coma decimal
        $sheet->setCellValue('D4', 15);   // Luis ev1 fuera de rango -> error
        // Luis ev2 vacío -> se omite sin borrar nada

        $response = $this->actingAs($this->teacher, 'sanctum')->post('/api/grades/excel-import', [
            'groupSubjectId' => $this->groupSubject->id,
            'periodId' => $this->period->id,
            'file' => $this->asUpload($spreadsheet),
        ], ['Accept' => 'application/json']);

        $response->assertOk();
        $this->assertSame(2, $response->json('saved'));
        $this->assertCount(1, $response->json('errors'));
        $this->assertDatabaseHas('grades', ['student_id' => $this->ana->id, 'grade_column_id' => $this->ev2->id, 'score' => 9.0]);
        $this->assertDatabaseMissing('grades', ['student_id' => $this->luis->id, 'grade_column_id' => $this->ev1->id]);

        $final = PeriodFinal::where('student_id', $this->ana->id)->where('period_id', $this->period->id)->first();
        $this->assertEquals(8.5, (float) $final->period_final);
    }

    public function test_a_file_that_is_not_the_template_is_rejected(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([['Apellidos', 'Nombres', 'Nota'], ['Gómez', 'Ana', 8]]);

        $this->actingAs($this->teacher, 'sanctum')->post('/api/grades/excel-import', [
            'groupSubjectId' => $this->groupSubject->id,
            'periodId' => $this->period->id,
            'file' => $this->asUpload($spreadsheet),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }
}
