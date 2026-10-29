<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los grupos de trabajo dejan de ser de una sola actividad: pasan a un conjunto
 * (`work_group_sets`) del curso y período que varias actividades comparten (p. ej. las
 * fases de un proyecto). La nota de cada grupo es por actividad (`work_group_grades`).
 *
 * Los grupos que ya existían se conservan: cada actividad con grupos queda con su
 * propio conjunto ("Grupos de <actividad>") y sus notas pasan a `work_group_grades`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $oldGroups = DB::table('work_groups')->get();
        $oldMembers = DB::table('work_group_members')->get();

        Schema::create('work_group_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('period_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->timestamps();
        });

        Schema::table('grade_columns', function (Blueprint $table) {
            $table->foreignId('work_group_set_id')->nullable()->constrained()->nullOnDelete();
        });

        // Se rehacen las dos tablas (cambian sus llaves); los datos se reinsertan abajo.
        Schema::drop('work_group_members');
        Schema::drop('work_groups');

        Schema::create('work_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_group_set_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('work_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_group_id')->constrained()->cascadeOnDelete();
            // Repetido a propósito: un estudiante está en un solo grupo del conjunto.
            $table->foreignId('work_group_set_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();

            $table->unique(['work_group_set_id', 'student_id']);
        });

        Schema::create('work_group_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grade_column_id')->constrained()->cascadeOnDelete();
            // Nota del grupo en esa actividad: un puntaje o una convención (NP, ✓…).
            $table->decimal('score', 4, 1)->nullable();
            $table->foreignId('convention_id')->nullable()->constrained('grade_conventions')->nullOnDelete();
            $table->timestamps();

            $table->unique(['work_group_id', 'grade_column_id']);
        });

        $now = now();
        foreach ($oldGroups->groupBy('grade_column_id') as $columnId => $groups) {
            $column = DB::table('grade_columns')->where('id', $columnId)->first();
            if (! $column) {
                continue;
            }
            $setId = DB::table('work_group_sets')->insertGetId([
                'group_subject_id' => $column->group_subject_id,
                'period_id' => $column->period_id,
                'name' => mb_substr('Grupos de '.$column->name, 0, 80),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('grade_columns')->where('id', $columnId)->update(['work_group_set_id' => $setId]);

            foreach ($groups as $group) {
                // Se conserva el id: así los enlaces que tenga el frontend en caché siguen sirviendo.
                DB::table('work_groups')->insert([
                    'id' => $group->id,
                    'work_group_set_id' => $setId,
                    'name' => $group->name,
                    'sort_order' => $group->sort_order,
                    'created_at' => $group->created_at,
                    'updated_at' => $group->updated_at,
                ]);
                DB::table('work_group_grades')->insert([
                    'work_group_id' => $group->id,
                    'grade_column_id' => $columnId,
                    'score' => $group->score,
                    'convention_id' => $group->convention_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                foreach ($oldMembers->where('work_group_id', $group->id) as $member) {
                    DB::table('work_group_members')->insert([
                        'work_group_id' => $group->id,
                        'work_group_set_id' => $setId,
                        'student_id' => $member->student_id,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $sets = DB::table('work_group_sets')->get()->keyBy('id');
        $groups = DB::table('work_groups')->get();
        $members = DB::table('work_group_members')->get();
        $grades = DB::table('work_group_grades')->get();
        $columns = DB::table('grade_columns')->whereNotNull('work_group_set_id')->get(['id', 'work_group_set_id']);

        Schema::drop('work_group_grades');
        Schema::drop('work_group_members');
        Schema::drop('work_groups');
        Schema::table('grade_columns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_group_set_id');
        });
        Schema::drop('work_group_sets');

        Schema::create('work_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_column_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->decimal('score', 4, 1)->nullable();
            $table->foreignId('convention_id')->nullable()->constrained('grade_conventions')->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('work_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grade_column_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->unique(['grade_column_id', 'student_id']);
        });

        // Cada actividad vuelve a tener su propia copia de los grupos de su conjunto.
        foreach ($columns as $column) {
            if (! $sets->has($column->work_group_set_id)) {
                continue;
            }
            foreach ($groups->where('work_group_set_id', $column->work_group_set_id) as $group) {
                $grade = $grades->first(fn ($g) => $g->work_group_id === $group->id && $g->grade_column_id === $column->id);
                $newId = DB::table('work_groups')->insertGetId([
                    'grade_column_id' => $column->id,
                    'name' => $group->name,
                    'score' => $grade?->score,
                    'convention_id' => $grade?->convention_id,
                    'sort_order' => $group->sort_order,
                    'created_at' => $group->created_at,
                    'updated_at' => $group->updated_at,
                ]);
                foreach ($members->where('work_group_id', $group->id) as $member) {
                    DB::table('work_group_members')->insert([
                        'work_group_id' => $newId,
                        'grade_column_id' => $column->id,
                        'student_id' => $member->student_id,
                    ]);
                }
            }
        }
    }
};
