<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institutions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('city');
            $table->string('department');
            $table->string('nit')->nullable();
            $table->string('rector')->nullable();
            $table->string('logo')->nullable();
            $table->enum('grading_scale', ['1_to_10', '1_to_5'])->default('1_to_10');
            $table->decimal('min_passing_grade', 3, 1)->default(6.0);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institutions');
    }
};
