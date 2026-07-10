<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_finals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('period_id')->constrained()->cascadeOnDelete();
            $table->decimal('period_final', 4, 1)->nullable();
            $table->boolean('is_promoted')->nullable();
            $table->boolean('manually_adjusted')->default(false);
            $table->text('adjustment_reason')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'group_subject_id', 'period_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_finals');
    }
};
