<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convenciones de calificación del docente (NP, NA, ✓, ✗…): una abreviatura o
 * ícono con su significado y, opcionalmente, la nota que vale. Una nota que usa
 * convención guarda su convention_id y su score es el valor de la convención
 * (null = no cuenta en el promedio).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_conventions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Binaria en MySQL: con utf8mb4_unicode_ci todos los emojis se comparan
            // como iguales y el índice único rechazaría 😊 si ya existe 😢.
            $code = $table->string('code', 10);
            if (DB::getDriverName() === 'mysql') {
                $code->collation('utf8mb4_bin');
            }
            $table->string('label', 100);
            $table->decimal('value', 4, 1)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'code']);
        });

        Schema::table('grades', function (Blueprint $table) {
            $table->foreignId('convention_id')->nullable()->after('score')
                ->constrained('grade_conventions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->dropConstrainedForeignId('convention_id');
        });
        Schema::dropIfExists('grade_conventions');
    }
};
