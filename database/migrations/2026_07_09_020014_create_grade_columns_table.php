<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('period_id')->constrained()->cascadeOnDelete();
            $table->enum('column_type', ['manual', 'from_attendance', 'section_average', 'custom_formula']);
            $table->string('name');
            $table->string('short_name', 8)->nullable();
            $table->text('description')->nullable();
            $table->decimal('weight', 5, 2)->default(0);
            $table->decimal('max_score', 5, 1)->default(10.0);
            $table->date('date')->nullable();
            // Solo para column_type = from_attendance
            $table->decimal('attendance_base_score', 4, 1)->default(10.0);
            $table->decimal('absence_penalty', 3, 1)->default(0.5);
            $table->decimal('justified_absence_penalty', 3, 1)->default(0.1);
            // Solo para column_type = custom_formula
            $table->text('formula')->nullable();
            $table->boolean('is_visible')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_columns');
    }
};
