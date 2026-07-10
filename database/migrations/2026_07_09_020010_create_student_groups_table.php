<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->date('enrollment_date');
            $table->enum('status', ['activo', 'retirado', 'trasladado'])->default('activo');
            $table->date('withdrawal_date')->nullable();
            $table->text('withdrawal_reason')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'group_id', 'academic_year_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_groups');
    }
};
