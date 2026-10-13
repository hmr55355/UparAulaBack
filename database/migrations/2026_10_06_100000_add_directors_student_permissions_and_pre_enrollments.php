<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - groups.director_id: docente director de grupo (ve el consolidado de todas las
 *   materias de su grupo, descarga boletines y hace la prematrícula).
 * - institutions.teachers_manage_students: si los docentes (director o quien dicta
 *   en el grupo) pueden matricular, retirar y trasladar estudiantes; por defecto
 *   solo los administradores.
 * - pre_enrollments: prematrícula para el año siguiente, una por estudiante y año.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->foreignId('director_id')->nullable()->after('shift_id')->constrained('users')->nullOnDelete();
        });

        Schema::table('institutions', function (Blueprint $table) {
            $table->boolean('teachers_manage_students')->default(false)->after('min_passing_grade');
        });

        Schema::create('pre_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            // Grupo en el que estaba al hacer la prematrícula.
            $table->foreignId('from_group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->unsignedSmallInteger('target_year');
            $table->foreignId('target_grade_level_id')->nullable()->constrained('grade_levels')->nullOnDelete();
            // pendiente | confirmada | no_continua (texto: se valida en el controlador).
            $table->string('status', 20)->default('pendiente');
            $table->foreignId('parent_id')->nullable()->constrained('parents')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('registered_by')->constrained('users');
            $table->timestamps();

            $table->unique(['student_id', 'target_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pre_enrollments');
        Schema::table('institutions', fn (Blueprint $table) => $table->dropColumn('teachers_manage_students'));
        Schema::table('groups', fn (Blueprint $table) => $table->dropConstrainedForeignId('director_id'));
    }
};
