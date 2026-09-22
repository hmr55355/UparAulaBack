<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Grados (Sexto…Once), jornadas (mañana/tarde/…) y bloques de horario por jornada.
 *
 * Todo es aditivo: tablas nuevas y columnas nuevas nulas en `groups`. El texto
 * libre `groups.grade_level` se conserva (lo siguen leyendo reportes/exportes)
 * y el backfill de abajo deja los datos existentes ya relacionados:
 *  - un grado por cada valor distinto de `groups.grade_level` de la institución,
 *  - una jornada "Jornada única" por institución con todos sus grupos,
 *  - cada materia vinculada a los grados donde ya está asignada.
 */
return new class extends Migration
{
    private const GRADE_NAMES = [
        0 => 'Transición', 1 => 'Primero', 2 => 'Segundo', 3 => 'Tercero', 4 => 'Cuarto', 5 => 'Quinto',
        6 => 'Sexto', 7 => 'Séptimo', 8 => 'Octavo', 9 => 'Noveno', 10 => 'Décimo', 11 => 'Once',
    ];

    public function up(): void
    {
        Schema::create('grade_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('level')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['institution_id', 'name']);
        });

        Schema::create('grade_level_subject', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_level_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->unique(['grade_level_id', 'subject_id']);
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['institution_id', 'name']);
        });

        Schema::create('class_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('clase'); // clase | descanso
            $table->string('label', 50);
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->foreignId('grade_level_id')->nullable()->after('academic_year_id')->constrained()->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->after('grade_level_id')->constrained()->nullOnDelete();
        });

        $this->backfill();
    }

    private function backfill(): void
    {
        $now = now();

        foreach (DB::table('institutions')->pluck('id') as $institutionId) {
            $shiftId = DB::table('shifts')->insertGetId([
                'institution_id' => $institutionId, 'name' => 'Jornada única', 'sort_order' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('groups')->where('institution_id', $institutionId)->update(['shift_id' => $shiftId]);

            $rawLevels = DB::table('groups')->where('institution_id', $institutionId)
                ->whereNotNull('grade_level')->distinct()->pluck('grade_level');

            foreach ($rawLevels as $raw) {
                $trimmed = trim((string) $raw);
                if ($trimmed === '') {
                    continue;
                }
                $level = ctype_digit($trimmed) ? (int) $trimmed : null;
                $name = $level !== null ? (self::GRADE_NAMES[$level] ?? $trimmed) : $trimmed;

                $gradeLevelId = DB::table('grade_levels')->where('institution_id', $institutionId)->where('name', $name)->value('id')
                    ?? DB::table('grade_levels')->insertGetId([
                        'institution_id' => $institutionId, 'name' => $name, 'level' => $level,
                        'sort_order' => $level ?? 99, 'created_at' => $now, 'updated_at' => $now,
                    ]);

                DB::table('groups')->where('institution_id', $institutionId)->where('grade_level', $raw)
                    ->update(['grade_level_id' => $gradeLevelId]);
            }
        }

        $pairs = DB::table('group_subjects')
            ->join('groups', 'groups.id', '=', 'group_subjects.group_id')
            ->whereNotNull('groups.grade_level_id')
            ->distinct()
            ->get(['groups.grade_level_id', 'group_subjects.subject_id']);

        foreach ($pairs as $pair) {
            DB::table('grade_level_subject')->insertOrIgnore([
                'grade_level_id' => $pair->grade_level_id, 'subject_id' => $pair->subject_id,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shift_id');
            $table->dropConstrainedForeignId('grade_level_id');
        });
        Schema::dropIfExists('class_blocks');
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('grade_level_subject');
        Schema::dropIfExists('grade_levels');
    }
};
