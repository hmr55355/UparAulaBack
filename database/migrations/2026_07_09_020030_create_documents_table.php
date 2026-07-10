<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('related_type', ['student', 'annotation', 'citation', 'observation', 'homework']);
            $table->unsignedBigInteger('related_id');
            $table->string('title');
            $table->string('file_path');
            $table->string('file_name');
            $table->enum('file_type', ['image', 'pdf', 'doc']);
            $table->integer('file_size');
            $table->timestamps();

            $table->index(['related_type', 'related_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
