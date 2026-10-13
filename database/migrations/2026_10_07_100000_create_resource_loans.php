<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Préstamo de recursos (libros, materiales, portátiles, material tecnológico…) a
 * estudiantes o docentes. No hay inventario: cada préstamo guarda el tipo de
 * recurso y el recurso escrito a mano ("micro:bit"). "Vencido" no se guarda: es un
 * préstamo sin devolver con la fecha de devolución ya pasada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            // Quien entrega y responde por el préstamo.
            $table->foreignId('registered_by')->constrained('users');
            // Se le presta a un estudiante o a un docente (uno de los dos).
            $table->foreignId('student_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('borrower_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            // Grupo del estudiante al momento del préstamo (para filtrar y ubicarlo).
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            // Texto, no enum: la lista vive en ResourceLoan::CATEGORIES.
            $table->string('category', 30);
            $table->string('item_name', 150);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('item_code', 60)->nullable();
            $table->date('loan_date');
            $table->date('due_date');
            // prestado | devuelto | perdido
            $table->string('status', 20)->default('prestado');
            $table->date('returned_at')->nullable();
            // bueno | danado | incompleto
            $table->string('return_condition', 20)->nullable();
            $table->text('notes')->nullable();
            $table->text('return_notes')->nullable();
            $table->timestamps();

            $table->index(['institution_id', 'status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_loans');
    }
};
