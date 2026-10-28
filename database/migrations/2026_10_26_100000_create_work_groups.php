<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grupos de trabajo dentro de una actividad (columna manual de la planilla). La
 * nota del grupo se copia a la nota de cada integrante en `grades`: el motor de
 * notas no sabe nada de grupos, y el docente puede cambiarle la nota a uno solo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_column_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            // Nota del grupo: un puntaje o una convención (NP, ✓…), como una celda.
            $table->decimal('score', 4, 1)->nullable();
            $table->foreignId('convention_id')->nullable()->constrained('grade_conventions')->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('work_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_group_id')->constrained()->cascadeOnDelete();
            // Repetido a propósito: un estudiante está en un solo grupo por actividad.
            $table->foreignId('grade_column_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();

            $table->unique(['grade_column_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_group_members');
        Schema::dropIfExists('work_groups');
    }
};
