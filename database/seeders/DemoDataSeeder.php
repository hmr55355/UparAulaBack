<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\ClassPlan;
use App\Models\ClassSchedule;
use App\Models\Grade;
use App\Models\GradeSection;
use App\Models\GradeTemplate;
use App\Models\ClassBlock;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\Shift;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds one fully working demo tenant matching el horario real del docente
 * (imagen aSc Timetables compartida por el usuario): 7 grupos, 3 materias, 8
 * group_subjects, 22 horas de clase/semana en bloques con descanso, ~30 estudiantes por grupo, y
 * asistencia/notas históricas para los días de esta semana que ya pasaron —
 * para que un docente pueda probar la app contra una semana realista completa
 * en vez de una app vacía. Mirrors "NOTAS FINALES PARA EL AGENTE" #19 y #34.
 */
class DemoDataSeeder extends Seeder
{
    private array $namePool;

    private int $nextDocument = 1000000;

    public function run(): void
    {
        $this->namePool = json_decode(
            file_get_contents(__DIR__.'/data/name_pool.json'),
            true
        );

        $teacher = User::create([
            'name' => 'Hernis Mercado',
            'email' => 'hernis@uparaula.com',
            'password' => Hash::make('password'),
            'phone' => '3001234567',
        ]);

        $secondTeacher = User::create([
            'name' => 'Laura Gómez',
            'email' => 'segundo.docente@uparaula.com',
            'password' => Hash::make('password'),
            'phone' => '3007654321',
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

        // Segundo docente: solo miembro activo de la institución, sin horario
        // propio — se usa para probar reglas de autorización entre docentes.
        InstitutionTeacher::create([
            'institution_id' => $institution->id,
            'user_id' => $secondTeacher->id,
            'role' => 'teacher',
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
            [1, 'Primer Período', '2026-01-20', '2026-03-20'],
            [2, 'Segundo Período', '2026-03-23', '2026-05-29'],
            [3, 'Tercer Período', '2026-06-01', '2026-08-07'],
            [4, 'Cuarto Período', '2026-08-10', '2026-11-28'],
        ];

        // El período "activo" es el que realmente contiene la fecha de hoy
        // (no siempre el Primer Período) — de lo contrario la asistencia
        // histórica sembrada con fechas reales de "esta semana" cae fuera del
        // rango del período activo y las columnas from_attendance nunca la ven.
        $today = Carbon::now()->toDateString();
        $activePeriodNumber = collect($periodRanges)->first(fn ($p) => $today >= $p[2] && $today <= $p[3])[0] ?? 1;

        $periods = collect($periodRanges)->map(fn ($p) => Period::create([
            'academic_year_id' => $academicYear->id,
            'number' => $p[0],
            'name' => $p[1],
            'start_date' => $p[2],
            'end_date' => $p[3],
            'is_active' => $p[0] === $activePeriodNumber,
        ]));
        $activePeriod = $periods->firstWhere('number', $activePeriodNumber);

        // --- Grupos (7, calcados del horario real) ---
        $groupSpecs = [
            '1101' => ['grade' => '11', 'section' => '1'],
            '1102' => ['grade' => '11', 'section' => '2'],
            '1103' => ['grade' => '11', 'section' => '3'],
            '1001' => ['grade' => '10', 'section' => '1'],
            '1002' => ['grade' => '10', 'section' => '2'],
            '1003' => ['grade' => '10', 'section' => '3'],
            '1004' => ['grade' => '10', 'section' => '4'],
        ];

        // --- Grados y jornada (con los bloques reales del horario del docente) ---
        $gradeLevels = [
            '10' => GradeLevel::create(['institution_id' => $institution->id, 'name' => 'Décimo', 'level' => 10, 'sort_order' => 10]),
            '11' => GradeLevel::create(['institution_id' => $institution->id, 'name' => 'Once', 'level' => 11, 'sort_order' => 11]),
        ];
        $shift = Shift::create(['institution_id' => $institution->id, 'name' => 'Mañana', 'sort_order' => 0]);
        $blockSpecs = [
            ['clase', '1', '06:15', '07:10'], ['clase', '2', '07:10', '08:05'], ['clase', '3', '08:05', '09:00'],
            ['descanso', 'Descanso', '09:00', '09:30'],
            ['clase', '4', '09:30', '10:25'], ['clase', '5', '10:25', '11:20'], ['clase', '6', '11:20', '12:15'],
        ];
        foreach ($blockSpecs as $index => [$type, $label, $start, $end]) {
            ClassBlock::create([
                'shift_id' => $shift->id, 'type' => $type, 'label' => $label,
                'start_time' => $start, 'end_time' => $end, 'sort_order' => $index,
            ]);
        }

        /** @var array<string, Group> $groups */
        $groups = [];
        foreach ($groupSpecs as $name => $meta) {
            $groups[$name] = Group::create([
                'institution_id' => $institution->id,
                'academic_year_id' => $academicYear->id,
                'grade_level_id' => $gradeLevels[$meta['grade']]->id,
                'shift_id' => $shift->id,
                'name' => $name,
                'grade_level' => $meta['grade'],
                'section' => $meta['section'],
            ]);
        }

        // --- Materias (3), vinculadas a sus grados ---
        $calculo = Subject::create(['institution_id' => $institution->id, 'name' => 'Cálculo', 'color' => '#1565C0']);
        $trigonometria = Subject::create(['institution_id' => $institution->id, 'name' => 'Trigonometría', 'color' => '#1976D2']);
        $estadistica = Subject::create(['institution_id' => $institution->id, 'name' => 'Estadística', 'color' => '#2E7D32']);
        $calculo->gradeLevels()->sync([$gradeLevels['11']->id]);
        $trigonometria->gradeLevels()->sync([$gradeLevels['10']->id]);
        $estadistica->gradeLevels()->sync([$gradeLevels['10']->id]);

        // --- Group-subjects (8), todos dictados por Hernis ---
        $groupSubjectSpecs = [
            ['1101', $calculo], ['1102', $calculo], ['1103', $calculo],
            ['1001', $trigonometria], ['1002', $trigonometria], ['1003', $trigonometria], ['1004', $trigonometria],
            ['1003', $estadistica],
        ];

        /** @var array<string, GroupSubject> $groupSubjects keyed by "grupo:materia" */
        $groupSubjects = [];
        foreach ($groupSubjectSpecs as [$groupName, $subject]) {
            $key = "{$groupName}:{$subject->name}";
            $groupSubjects[$key] = GroupSubject::create([
                'group_id' => $groups[$groupName]->id,
                'subject_id' => $subject->id,
                'user_id' => $teacher->id,
                'institution_id' => $institution->id,
                'academic_year_id' => $academicYear->id,
            ]);
        }

        // --- ~30 estudiantes por grupo ---
        /** @var array<string, Student[]> $studentsByGroup */
        $studentsByGroup = [];
        foreach ($groups as $name => $group) {
            $studentsByGroup[$name] = $this->seedStudentsForGroup($institution, $academicYear, $group, 30);
        }

        // --- Plantilla de calificaciones, aplicada a los 8 group_subjects ---
        $sectionsConfig = $this->gradeSectionsConfig();
        $template = GradeTemplate::create([
            'user_id' => $teacher->id,
            'institution_id' => $institution->id,
            'name' => 'Plantilla estándar 2026',
            'description' => 'Aptitud, Tareas, Actividades y Evaluaciones — Período 1.',
            'is_shared' => true,
            'sections_config' => $sectionsConfig,
        ]);

        $calculator = app(GradeCalculatorService::class);
        foreach ($groupSubjects as $groupSubject) {
            $calculator->applyTemplate($template, $groupSubject, $activePeriod);
        }

        // --- Horario real (22 horas de clase/semana, calcado del horario aSc del docente) ---
        // [día ISO (1=lun), hora inicio, hora fin, salón, grupo, materia]
        $scheduleRows = [
            [1, '06:15:00', '07:10:00', 'HC.1101', '1101', 'Cálculo'],
            [1, '07:10:00', '08:05:00', 'HC.1101', '1101', 'Cálculo'],
            [1, '08:05:00', '09:00:00', 'HC.1003', '1003', 'Trigonometría'],
            [1, '09:30:00', '10:25:00', 'HC.1003', '1003', 'Trigonometría'],
            [1, '10:25:00', '11:20:00', 'HC.1102', '1102', 'Cálculo'],
            [1, '11:20:00', '12:15:00', 'HC.1102', '1102', 'Cálculo'],

            [2, '07:10:00', '08:05:00', 'HC.1002', '1002', 'Trigonometría'],
            [2, '09:30:00', '10:25:00', 'HC.1003', '1003', 'Estadística'],
            [2, '10:25:00', '11:20:00', 'HC.1102', '1102', 'Cálculo'],
            [2, '11:20:00', '12:15:00', 'HC.1101', '1101', 'Cálculo'],

            [3, '07:10:00', '08:05:00', 'HC.1001', '1001', 'Trigonometría'],
            [3, '08:05:00', '09:00:00', null, '1004', 'Trigonometría'],
            [3, '09:30:00', '10:25:00', null, '1004', 'Trigonometría'],
            [3, '11:20:00', '12:15:00', 'HC.1103', '1103', 'Cálculo'],

            [4, '08:05:00', '09:00:00', 'HC.1001', '1001', 'Trigonometría'],
            [4, '09:30:00', '10:25:00', 'HC.1001', '1001', 'Trigonometría'],
            [4, '10:25:00', '11:20:00', 'HC.1002', '1002', 'Trigonometría'],
            [4, '11:20:00', '12:15:00', 'HC.1002', '1002', 'Trigonometría'],

            [5, '07:10:00', '08:05:00', 'HC.1103', '1103', 'Cálculo'],
            [5, '08:05:00', '09:00:00', 'HC.1103', '1103', 'Cálculo'],
            [5, '09:30:00', '10:25:00', null, '1004', 'Trigonometría'],
            [5, '11:20:00', '12:15:00', 'HC.1003', '1003', 'Trigonometría'],
        ];

        foreach ($scheduleRows as [$day, $start, $end, $classroom, $groupName, $subjectName]) {
            ClassSchedule::create([
                'group_subject_id' => $groupSubjects["{$groupName}:{$subjectName}"]->id,
                'user_id' => $teacher->id,
                'day_of_week' => $day,
                'start_time' => $start,
                'end_time' => $end,
                'classroom' => $classroom,
                'academic_year_id' => $academicYear->id,
            ]);
        }

        // --- Asistencia histórica: días de esta semana que ya pasaron (no incluye hoy) ---
        $todayWeekday = min(Carbon::now()->isoWeekday(), 6);
        $monday = Carbon::now()->startOfWeek(Carbon::MONDAY);

        // Una sola sesión por (día, group_subject) — un bloque doble como el
        // lunes 1101 (períodos 1 y 2) es UNA sola asistencia, no dos.
        $sessions = collect($scheduleRows)
            ->filter(fn ($row) => $row[0] < $todayWeekday)
            ->unique(fn ($row) => $row[0].':'.$row[4].':'.$row[5]);

        foreach ($sessions as [$day, , , , $groupName, $subjectName]) {
            $groupSubject = $groupSubjects["{$groupName}:{$subjectName}"];
            $date = $monday->copy()->addDays($day - 1)->toDateString();

            foreach ($studentsByGroup[$groupName] as $student) {
                AttendanceRecord::withoutEvents(fn () => AttendanceRecord::create([
                    'student_id' => $student->id,
                    'group_subject_id' => $groupSubject->id,
                    'date' => $date,
                    'status' => $this->randomAttendanceStatus(),
                    'registered_by' => $teacher->id,
                ]));
            }
        }

        // --- Notas históricas: calificaciones manuales para el período activo ---
        foreach ($groupSubjects as $key => $groupSubject) {
            $groupName = explode(':', $key)[0];
            $manualColumns = GradeSection::where('group_subject_id', $groupSubject->id)
                ->where('period_id', $activePeriod->id)
                ->with('columns')
                ->get()
                ->flatMap->columns
                ->where('column_type', 'manual');

            foreach ($studentsByGroup[$groupName] as $student) {
                foreach ($manualColumns as $column) {
                    Grade::withoutEvents(fn () => Grade::create([
                        'student_id' => $student->id,
                        'grade_column_id' => $column->id,
                        'group_subject_id' => $groupSubject->id,
                        'period_id' => $activePeriod->id,
                        'score' => $this->randomScore(),
                        'registered_by' => $teacher->id,
                    ]));
                }
            }
        }

        // --- Barrido final: asistencia + notas manuales -> columnas calculadas, finales de sección y definitiva ---
        foreach ($groupSubjects as $key => $groupSubject) {
            $groupName = explode(':', $key)[0];
            foreach ($studentsByGroup[$groupName] as $student) {
                $calculator->recalculateForStudent($student->id, $groupSubject->id, $activePeriod->id);
            }
        }

        ClassPlan::create([
            'group_subject_id' => $groupSubjects['1101:Cálculo']->id,
            'period_id' => $activePeriod->id,
            'registered_by' => $teacher->id,
            'date' => '2026-02-20',
            'topic' => 'Límites y continuidad',
            'objectives' => 'Reconocer el concepto de límite de una función en un punto.',
            'activities' => 'Explicación en tablero + ejercicios guiados página 82.',
            'what_was_done' => 'Se explicó la teoría y se resolvieron los ejercicios 1 al 10 de la página 82.',
            'pending_for_next_class' => 'Repasar ejercicios de la página 82 que quedaron incompletos.',
            'attendance_note' => '28 de 30 estudiantes',
            'status' => 'ejecutada',
        ]);
    }

    /**
     * @return Student[]
     */
    private function seedStudentsForGroup(Institution $institution, AcademicYear $academicYear, Group $group, int $count): array
    {
        $maleNames = $this->namePool['first_names_m'];
        $femaleNames = $this->namePool['first_names_f'];
        $lastNames = $this->namePool['last_names'];

        $students = [];
        for ($i = 0; $i < $count; $i++) {
            $isMale = mt_rand(0, 1) === 0;
            $firstName = $isMale ? $maleNames[array_rand($maleNames)] : $femaleNames[array_rand($femaleNames)];
            $lastName = $lastNames[array_rand($lastNames)].' '.$lastNames[array_rand($lastNames)];

            $student = Student::create([
                'institution_id' => $institution->id,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'document_type' => 'TI',
                'document_number' => (string) ($this->nextDocument++),
                'gender' => $isMale ? 'masculino' : 'femenino',
                'is_active' => true,
            ]);

            StudentGroup::create([
                'student_id' => $student->id,
                'group_id' => $group->id,
                'academic_year_id' => $academicYear->id,
                'enrollment_date' => $academicYear->start_date,
                'status' => 'activo',
            ]);

            $students[] = $student;
        }

        $group->update(['student_count' => $count]);

        return $students;
    }

    private function randomAttendanceStatus(): string
    {
        $roll = mt_rand(1, 100);

        return match (true) {
            $roll <= 80 => 'presente',
            $roll <= 90 => 'ausente_injustificado',
            $roll <= 95 => 'tarde',
            default => 'ausente_justificado',
        };
    }

    private function randomScore(): float
    {
        $roll = mt_rand(1, 100);

        return match (true) {
            $roll <= 10 => round(mt_rand(20, 55) / 10, 1),
            $roll <= 30 => round(mt_rand(56, 69) / 10, 1),
            default => round(mt_rand(70, 100) / 10, 1),
        };
    }

    private function gradeSectionsConfig(): array
    {
        return [
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
    }
}
