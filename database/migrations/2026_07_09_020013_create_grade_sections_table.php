<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('period_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('short_name', 12)->nullable();
            $table->decimal('weight', 5, 2);
            $table->string('color')->default('#1565C0');
            $table->boolean('has_section_final')->default(true);
            $table->string('section_final_label')->default('Def');
            $table->enum('final_calculation', ['weighted_avg', 'simple_avg', 'manual'])->default('weighted_avg');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_sections');
    }
};
