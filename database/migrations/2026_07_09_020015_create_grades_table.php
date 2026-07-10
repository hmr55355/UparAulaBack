<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grade_column_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('period_id')->constrained()->cascadeOnDelete();
            $table->decimal('score', 4, 1)->nullable();
            $table->boolean('is_excused')->default(false);
            $table->text('excused_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('registered_by')->constrained('users');
            $table->timestamps();

            $table->unique(['student_id', 'grade_column_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grades');
    }
};
