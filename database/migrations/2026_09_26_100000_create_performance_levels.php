<?php

use App\Services\PerformanceScale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Escala de valoración institucional (niveles de desempeño con su equivalencia a
 * la escala nacional). Cada institución existente recibe la escala sugerida para
 * su escala y nota mínima actuales, así los colores de la planilla no cambian de
 * golpe más allá de pasar de 3 a 4 niveles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            // bajo | basico | alto | superior (texto, no enum: se valida en el controlador).
            $table->string('national_level', 20);
            $table->decimal('min_score', 4, 1);
            $table->decimal('max_score', 4, 1);
            $table->string('color', 7);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        DB::table('institutions')->get(['id', 'grading_scale', 'min_passing_grade'])->each(function ($institution) use ($now) {
            foreach (PerformanceScale::defaultsFor($institution->grading_scale, (float) $institution->min_passing_grade) as $index => $level) {
                DB::table('performance_levels')->insert($level + [
                    'institution_id' => $institution->id,
                    'sort_order' => $index,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_levels');
    }
};
