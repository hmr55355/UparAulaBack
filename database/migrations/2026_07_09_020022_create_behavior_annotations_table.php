<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('behavior_annotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_subject_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('registered_by')->constrained('users');
            $table->date('date');
            $table->enum('type', ['positiva', 'negativa', 'informativa', 'acuerdo']);
            $table->enum('category', [
                'academico', 'convivencia', 'puntualidad', 'presentacion', 'participacion', 'actitud', 'otro',
            ]);
            $table->string('title');
            $table->text('description');
            $table->text('action_taken')->nullable();
            $table->boolean('requires_parent_contact')->default(false);
            $table->boolean('parent_contacted')->default(false);
            $table->date('parent_contact_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('behavior_annotations');
    }
};
