<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homeworks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('registered_by')->constrained('users');
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('assigned_date');
            $table->date('due_date');
            $table->decimal('max_score', 4, 1)->default(10.0);
            $table->boolean('is_graded')->default(false);
            $table->foreignId('grade_column_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homeworks');
    }
};
