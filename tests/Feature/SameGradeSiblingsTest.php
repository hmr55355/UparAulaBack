<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\GradeSection;
use App\Models\Group;
use App\Models\GroupSubject;
use App\Models\Institution;
use App\Models\InstitutionTeacher;
use App\Models\Period;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "Agregar también en los otros cursos del grado" para actividades y cobros. */
class SameGradeSiblingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_siblings_are_my_courses_of_the_same_subject_and_grade(): void
    {
        $teacher = User::factory()->create();
        $other = User::factory()->create();
        $institution = Institution::create(['name' => 'C', 'city' => 'V', 'department' => 'C', 'min_passing_grade' => 6, 'created_by' => $teacher->id]);
        foreach ([$teacher, $other] as $u) {
            InstitutionTeacher::create(['institution_id' => $institution->id, 'user_id' => $u->id, 'role' => 'teacher', 'status' => 'active']);
        }
        $year = AcademicYear::create(['institution_id' => $institution->id, 'year' => 2026, 'start_date' => '2026-01-20', 'end_date' => '2026-11-28']);
        $period = Period::create(['academic_year_id' => $year->id, 'number' => 1, 'name' => 'P1', 'start_date' => '2026-01-20', 'end_date' => '2026-03-20', 'is_active' => true]);
        $group = fn ($name, $grade) => Group::create(['institution_id' => $institution->id, 'academic_year_id' => $year->id, 'name' => $name, 'grade_level' => $grade]);
        [$g1, $g2, $g3, $g4, $g5] = [$group('1101', '11'), $group('1102', '11'), $group('1103', '11'), $group('1104', '11'), $group('1001', '10')];
        $math = Subject::create(['institution_id' => $institution->id, 'name' => 'Cálculo']);
        $physics = Subject::create(['institution_id' => $institution->id, 'name' => 'Física']);
        $course = fn ($g, $s, $u) => GroupSubject::create(['group_id' => $g->id, 'subject_id' => $s->id, 'user_id' => $u->id, 'institution_id' => $institution->id, 'academic_year_id' => $year->id]);
        $section = fn ($gs, $name) => GradeSection::create(['group_subject_id' => $gs->id, 'period_id' => $period->id, 'name' => $name, 'weight' => 100, 'sort_order' => 0]);

        $c1 = $course($g1, $math, $teacher);
        $c2 = $course($g2, $math, $teacher);
        $c3 = $course($g3, $math, $teacher);
        $course($g4, $math, $other);      // otro docente
        $course($g5, $math, $teacher);    // otro grado
        $course($g4, $physics, $teacher); // otra materia, pero dicta en 1104
        $s1 = $section($c1, 'Aptitud');
        $s2 = $section($c2, ' aptitud ');
        $section($c3, 'Cognitivo');       // no tiene la sección

        $this->actingAs($teacher, 'sanctum')->getJson("/api/grade-columns/siblings?sectionId={$s1->id}")
            ->assertOk()
            ->assertExactJson(['data' => [
                ['group_subject_id' => $c2->id, 'group_name' => '1102', 'section_id' => $s2->id],
                ['group_subject_id' => $c3->id, 'group_name' => '1103', 'section_id' => null],
            ]]);
        $this->actingAs($other, 'sanctum')->getJson("/api/grade-columns/siblings?sectionId={$s1->id}")->assertForbidden();

        // Cobros: grupos del grado donde dicto (cualquier materia) o que dirijo.
        $g3->update(['director_id' => $other->id]);
        $this->actingAs($teacher, 'sanctum')->getJson("/api/copy-charges/sibling-groups?groupId={$g1->id}")
            ->assertOk()
            ->assertJsonPath('data.*.name', ['1102', '1103', '1104']);
    }
}
