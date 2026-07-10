<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\Grade;
use App\Models\GradeColumn;
use App\Models\GradeSection;
use App\Models\Group;
use App\Models\GroupSubject;
use App\Models\Institution;
use App\Models\Period;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\GradeCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GradeCalculatorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_calculates_attendance_section_and_period_finals(): void
    {
        $teacher = User::factory()->create();
        $institution = Institution::create([
            'name' => 'Colegio Test', 'city' => 'Cali', 'department' => 'Valle',
            'min_passing_grade' => 6.0, 'created_by' => $teacher->id,
        ]);
        $academicYear = AcademicYear::create([
            'institution_id' => $institution->id, 'year' => 2026,
            'start_date' => '2026-01-20', 'end_date' => '2026-11-28',
        ]);
        $period = Period::create([
            'academic_year_id' => $academicYear->id, 'number' => 1, 'name' => 'Primer Período',
            'start_date' => '2026-01-20', 'end_date' => '2026-03-20', 'is_active' => true,
        ]);
        $group = Group::create([
            'institution_id' => $institution->id, 'academic_year_id' => $academicYear->id,
            'name' => '10-1', 'grade_level' => '10',
        ]);
        $subject = Subject::create(['institution_id' => $institution->id, 'name' => 'Matemáticas']);
        $groupSubject = GroupSubject::create([
            'group_id' => $group->id, 'subject_id' => $subject->id, 'user_id' => $teacher->id,
            'institution_id' => $institution->id, 'academic_year_id' => $academicYear->id,
        ]);
        $student = Student::create([
            'institution_id' => $institution->id, 'first_name' => 'Ana', 'last_name' => 'Gómez',
        ]);

        $aptitud = GradeSection::create([
            'group_subject_id' => $groupSubject->id, 'period_id' => $period->id,
            'name' => 'Aptitud', 'weight' => 40, 'final_calculation' => 'weighted_avg',
        ]);
        $asistencia = GradeColumn::create([
            'grade_section_id' => $aptitud->id, 'group_subject_id' => $groupSubject->id, 'period_id' => $period->id,
            'column_type' => 'from_attendance', 'name' => 'Asistencia', 'short_name' => 'Asist', 'weight' => 100,
            'attendance_base_score' => 10.0, 'absence_penalty' => 0.5, 'justified_absence_penalty' => 0.1,
        ]);

        $evaluaciones = GradeSection::create([
            'group_subject_id' => $groupSubject->id, 'period_id' => $period->id,
            'name' => 'Evaluaciones', 'weight' => 60, 'final_calculation' => 'weighted_avg',
        ]);
        $ev1 = GradeColumn::create([
            'grade_section_id' => $evaluaciones->id, 'group_subject_id' => $groupSubject->id, 'period_id' => $period->id,
            'column_type' => 'manual', 'name' => 'ev1', 'short_name' => 'ev1', 'weight' => 50,
        ]);
        $ev2 = GradeColumn::create([
            'grade_section_id' => $evaluaciones->id, 'group_subject_id' => $groupSubject->id, 'period_id' => $period->id,
            'column_type' => 'manual', 'name' => 'ev2', 'short_name' => 'ev2', 'weight' => 50,
        ]);

        // Dos faltas injustificadas dentro del rango del período.
        AttendanceRecord::create([
            'student_id' => $student->id, 'group_subject_id' => $groupSubject->id,
            'date' => '2026-02-01', 'status' => 'ausente_injustificado', 'registered_by' => $teacher->id,
        ]);
        AttendanceRecord::create([
            'student_id' => $student->id, 'group_subject_id' => $groupSubject->id,
            'date' => '2026-02-08', 'status' => 'ausente_injustificado', 'registered_by' => $teacher->id,
        ]);

        Grade::create([
            'student_id' => $student->id, 'grade_column_id' => $ev1->id,
            'group_subject_id' => $groupSubject->id, 'period_id' => $period->id,
            'score' => 8.0, 'registered_by' => $teacher->id,
        ]);
        Grade::create([
            'student_id' => $student->id, 'grade_column_id' => $ev2->id,
            'group_subject_id' => $groupSubject->id, 'period_id' => $period->id,
            'score' => 6.0, 'registered_by' => $teacher->id,
        ]);

        app(GradeCalculatorService::class)->recalculateForStudent($student->id, $groupSubject->id, $period->id);

        // Asistencia: 10 - 2 * 0.5 = 9.0
        $this->assertEquals(9.0, (float) Grade::where('grade_column_id', $asistencia->id)->first()->score);

        // Aptitud section final: única columna con peso 100% => 9.0
        $this->assertEquals(9.0, (float) $aptitud->sectionFinals()->first()->section_final);

        // Evaluaciones section final: (8*50 + 6*50) / 100 = 7.0
        $this->assertEquals(7.0, (float) $evaluaciones->sectionFinals()->first()->section_final);

        // Def Total: (9.0*40 + 7.0*60) / 100 = 7.8
        $periodFinal = \App\Models\PeriodFinal::where('student_id', $student->id)->first();
        $this->assertEquals(7.8, (float) $periodFinal->period_final);
        $this->assertTrue((bool) $periodFinal->is_promoted);
    }

    public function test_custom_formula_column_returns_null_when_a_reference_is_ungraded(): void
    {
        $teacher = User::factory()->create();
        $institution = Institution::create([
            'name' => 'Colegio Test 2', 'city' => 'Cali', 'department' => 'Valle', 'created_by' => $teacher->id,
        ]);
        $academicYear = AcademicYear::create([
            'institution_id' => $institution->id, 'year' => 2026,
            'start_date' => '2026-01-20', 'end_date' => '2026-11-28',
        ]);
        $period = Period::create([
            'academic_year_id' => $academicYear->id, 'number' => 1, 'name' => 'Primer Período',
            'start_date' => '2026-01-20', 'end_date' => '2026-03-20', 'is_active' => true,
        ]);
        $group = Group::create([
            'institution_id' => $institution->id, 'academic_year_id' => $academicYear->id,
            'name' => '10-1', 'grade_level' => '10',
        ]);
        $subject = Subject::create(['institution_id' => $institution->id, 'name' => 'Matemáticas']);
        $groupSubject = GroupSubject::create([
            'group_id' => $group->id, 'subject_id' => $subject->id, 'user_id' => $teacher->id,
            'institution_id' => $institution->id, 'academic_year_id' => $academicYear->id,
        ]);
        $student = Student::create([
            'institution_id' => $institution->id, 'first_name' => 'Ana', 'last_name' => 'Gómez',
        ]);

        $section = GradeSection::create([
            'group_subject_id' => $groupSubject->id, 'period_id' => $period->id,
            'name' => 'Evaluaciones', 'weight' => 100,
        ]);
        $ev1 = GradeColumn::create([
            'grade_section_id' => $section->id, 'group_subject_id' => $groupSubject->id, 'period_id' => $period->id,
            'column_type' => 'manual', 'name' => 'ev1', 'short_name' => 'ev1', 'weight' => 40,
        ]);
        GradeColumn::create([
            'grade_section_id' => $section->id, 'group_subject_id' => $groupSubject->id, 'period_id' => $period->id,
            'column_type' => 'manual', 'name' => 'ev2', 'short_name' => 'ev2', 'weight' => 40,
        ]);
        $defCu = GradeColumn::create([
            'grade_section_id' => $section->id, 'group_subject_id' => $groupSubject->id, 'period_id' => $period->id,
            'column_type' => 'custom_formula', 'name' => 'def cu', 'short_name' => 'defcu', 'weight' => 20,
            'formula' => '(ev1 + ev2) / 2',
        ]);

        Grade::create([
            'student_id' => $student->id, 'grade_column_id' => $ev1->id,
            'group_subject_id' => $groupSubject->id, 'period_id' => $period->id,
            'score' => 8.0, 'registered_by' => $teacher->id,
        ]);
        // ev2 se queda sin calificar (null).

        app(GradeCalculatorService::class)->recalculateForStudent($student->id, $groupSubject->id, $period->id);

        $this->assertNull(Grade::where('grade_column_id', $defCu->id)->first()->score);
    }
}
