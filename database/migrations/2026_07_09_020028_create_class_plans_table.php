<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('registered_by')->constrained('users');
            $table->date('date');
            $table->string('topic');
            $table->text('objectives')->nullable();
            $table->text('activities')->nullable();
            $table->text('resources')->nullable();
            $table->text('what_was_done')->nullable();
            $table->text('pending_for_next_class')->nullable();
            $table->string('attendance_note')->nullable();
            $table->text('homework_assigned')->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['planeada', 'ejecutada', 'pendiente', 'cancelada'])->default('planeada');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_plans');
    }
};
