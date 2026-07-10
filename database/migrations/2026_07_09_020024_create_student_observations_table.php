<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('registered_by')->constrained('users');
            $table->foreignId('period_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->enum('type', ['academica', 'comportamental', 'familiar', 'salud', 'seguimiento', 'logro', 'otro']);
            $table->text('content');
            $table->boolean('is_private')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_observations');
    }
};
