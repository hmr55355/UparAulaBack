<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_subject_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->enum('status', [
                'presente', 'ausente_injustificado', 'ausente_justificado', 'tarde', 'retirado_temprano',
            ]);
            $table->text('justification')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('registered_by')->constrained('users');
            $table->timestamps();

            $table->unique(['student_id', 'group_subject_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
