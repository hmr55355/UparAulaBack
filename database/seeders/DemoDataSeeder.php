<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\ClassPlan;
use App\Models\ClassSchedule;
use App\Models\GradeTemplate;
use App\Models\Group;
use App\Models\GroupSubject;
use App\Models\Institution;
use App\Models\InstitutionTeacher;
use App\Models\Period;
use App\Models\Student;
use App\Models\StudentGroup;
use App\Models\Subject;
use App\Models\User;
use App\Services\GradeCalculatorService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds one fully working demo tenant so a teacher can log in and immediately
 * see a real, populated planilla instead of an empty app. Mirrors the demo
 * data described in "NOTAS FINALES PARA EL AGENTE" #19 and #34.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $teacher = User::create([
            'name' => 'Hernis Mercado',
            'email' => 'hernis@uparaula.com',
            'password' => Hash::make('password'),
            'phone' => '3001234567',
        ]);

        $institution = Institution::create([
            'name' => 'I.E. Manuel Germán Cuello Gutiérrez',
            'city' => 'Valledupar',
            'department' => 'Cesar',
            'rector' => 'Rector Demo',
            'grading_scale' => '1_to_10',
            'min_passing_grade' => 6.0,
            'created_by' => $teacher->id,
        ]);

        InstitutionTeacher::create([
            'institution_id' => $institution->id,
            'user_id' => $teacher->id,
            'role' => 'admin',
            'status' => 'active',
            'accepted_at' => now(),
        ]);

        $academicYear = AcademicYear::create([
            'institution_id' => $institution->id,
            'year' => 2026,
            'is_active' => true,
            'start_date' => '2026-01-20',
            'end_date' => '2026-11-28',
        ]);

        $periodRanges = [
            [1, 'Primer Período', '2026-01-20', '2026-03-20', true],
            [2, 'Segundo Período', '2026-03-23', '2026-05-29', false],
            [3, 'Tercer Período', '2026-06-01', '2026-08-07', false],
            [4, 'Cuarto Período', '2026-08-10', '2026-11-28', false],
        ];

        $periods = collect($periodRanges)->map(fn ($p) => Period::create([
            'academic_year_id' => $academicYear->id,
            'number' => $p[0],
            'name' => $p[1],
            'start_date' => $p[2],
            'end_date' => $p[3],
            'is_active' => $p[4],
        ]));
        $periodUno = $periods->first();

        $groupA = Group::create([
            'institution_id' => $institution->id,
            'academic_year_id' => $academicYear->id,
            'name' => '10-2MMGC',
            'grade_level' => '10',
            'section' => '2MMGC',
        ]);

        $groupB = Group::create([
            'institution_id' => $institution->id,
            'academic_year_id' => $academicYear->id,
            'name' => '11-1',
            'grade_level' => '11',
            'section' => '1',
        ]);

        $matematicas = Subject::create([
            'institution_id' => $institution->id,
            'name' => 'Matemáticas',
            'color' => '#1565C0',
        ]);

        $trigonometria = Subject::create([
            'institution_id' => $institution->id,
            'name' => 'Trigonometría',
            'color' => '#1976D2',
        ]);

        $groupSubjectA = GroupSubject::create([
            'group_id' => $groupA->id,
            'subject_id' => $matematicas->id,
            'user_id' => $teacher->id,
            'institution_id' => $institution->id,
            'academic_year_id' => $academicYear->id,
        ]);

        $groupSubjectB = GroupSubject::create([
            'group_id' => $groupB->id,
            'subject_id' => $trigonometria->id,
            'user_id' => $teacher->id,
            'institution_id' => $institution->id,
            'academic_year_id' => $academicYear->id,
        ]);

        $studentsData = [
            ['García Pérez', 'Juan David', 'M', $groupA],
            ['Martínez Rojas', 'Carlos Andrés', 'M', $groupA],
            ['Pérez Torres', 'María Camila', 'F', $groupA],
            ['Torres López', 'Laura Sofía', 'F', $groupB],
            ['Gutiérrez Díaz', 'Andrés Felipe', 'M', $groupB],
        ];

        foreach ($studentsData as $i => [$lastName, $firstName, $gender, $group]) {
            $student = Student::create([
                'institution_id' => $institution->id,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'document_type' => 'TI',
                'document_number' => (string) (1000000 + $i),
                'gender' => $gender === 'M' ? 'masculino' : 'femenino',
                'is_active' => true,
            ]);

            StudentGroup::create([
                'student_id' => $student->id,
                'group_id' => $group->id,
                'academic_year_id' => $academicYear->id,
                'enrollment_date' => $academicYear->start_date,
                'status' => 'activo',
            ]);

            $group->increment('student_count');
        }

        $sectionsConfig = [
            'sections' => [
                [
                    'name' => 'Aptitud', 'short_name' => 'Aptitud', 'weight' => 30, 'color' => '#1565C0',
                    'has_section_final' => true, 'final_calculation' => 'weighted_avg',
                    'columns' => [
                        ['name' => 'Asistencia', 'short_name' => 'Asist', 'column_type' => 'from_attendance', 'weight' => 40, 'attendance_base_score' => 10.0, 'absence_penalty' => 0.5, 'justified_absence_penalty' => 0.1],
                        ['name' => 'Comportamiento', 'short_name' => 'Com', 'column_type' => 'manual', 'weight' => 30],
                        ['name' => 'Autoevaluación', 'short_name' => 'Auto', 'column_type' => 'manual', 'weight' => 30],
                    ],
                ],
                [
                    'name' => 'Tareas', 'short_name' => 'Tareas', 'weight' => 15, 'color' => '#FFC107',
                    'has_section_final' => true, 'final_calculation' => 'weighted_avg',
                    'columns' => [
                        ['name' => 'Tarea 1', 'short_name' => 'T1', 'column_type' => 'manual', 'weight' => 50],
                        ['name' => 'Tarea 2', 'short_name' => 'T2', 'column_type' => 'manual', 'weight' => 50],
                    ],
                ],
                [
                    'name' => 'Actividades', 'short_name' => 'Activ', 'weight' => 25, 'color' => '#2E7D32',
                    'has_section_final' => true, 'final_calculation' => 'weighted_avg',
                    'columns' => [
                        ['name' => 'Gráficas', 'short_name' => 'Graf', 'column_type' => 'manual', 'weight' => 34],
                        ['name' => 'Dominio', 'short_name' => 'Dom', 'column_type' => 'manual', 'weight' => 33],
                        ['name' => 'Problemas complejos', 'short_name' => 'Prob', 'column_type' => 'manual', 'weight' => 33],
                    ],
                ],
                [
                    'name' => 'Evaluaciones', 'short_name' => 'Evals', 'weight' => 30, 'color' => '#C62828',
                    'has_section_final' => true, 'final_calculation' => 'weighted_avg',
                    'columns' => [
                        ['name' => 'ev1', 'short_name' => 'ev1', 'column_type' => 'manual', 'weight' => 34],
                        ['name' => 'ev2', 'short_name' => 'ev2', 'column_type' => 'manual', 'weight' => 33],
                        ['name' => 'ev3', 'short_name' => 'ev3', 'column_type' => 'manual', 'weight' => 33],
                    ],
                ],
            ],
        ];

        $template = GradeTemplate::create([
            'user_id' => $teacher->id,
            'institution_id' => $institution->id,
            'name' => 'Plantilla Matemáticas 2026',
            'description' => 'Aptitud, Tareas, Actividades y Evaluaciones — Período 1.',
            'is_shared' => true,
            'sections_config' => $sectionsConfig,
        ]);

        app(GradeCalculatorService::class)->applyTemplate($template, $groupSubjectA, $periodUno);

        // Horario de ejemplo: Lunes/Miércoles/Viernes Matemáticas 10-2MMGC, Martes/Jueves Trigonometría 11-1.
        foreach ([1, 3, 5] as $day) {
            ClassSchedule::create([
                'group_subject_id' => $groupSubjectA->id,
                'user_id' => $teacher->id,
                'day_of_week' => $day,
                'start_time' => '07:00:00',
                'end_time' => '07:50:00',
                'classroom' => 'Salón 10-2',
                'block_label' => '1ra hora',
                'academic_year_id' => $academicYear->id,
            ]);
        }

        foreach ([2, 4] as $day) {
            ClassSchedule::create([
                'group_subject_id' => $groupSubjectB->id,
                'user_id' => $teacher->id,
                'day_of_week' => $day,
                'start_time' => '08:00:00',
                'end_time' => '08:50:00',
                'classroom' => 'Salón 11-1',
                'block_label' => '2da hora',
                'academic_year_id' => $academicYear->id,
            ]);
        }

        ClassPlan::create([
            'group_subject_id' => $groupSubjectA->id,
            'period_id' => $periodUno->id,
            'registered_by' => $teacher->id,
            'date' => '2026-02-20',
            'topic' => 'Ángulos de referencia',
            'objectives' => 'Reconocer ángulos de referencia en los cuatro cuadrantes.',
            'activities' => 'Explicación en tablero + ejercicios guiados página 82.',
            'what_was_done' => 'Se explicó la teoría y se resolvieron los ejercicios 1 al 10 de la página 82.',
            'pending_for_next_class' => 'Repasar ejercicios de la página 82 que quedaron incompletos.',
            'attendance_note' => '28 de 32 estudiantes',
            'status' => 'ejecutada',
        ]);
    }
}
