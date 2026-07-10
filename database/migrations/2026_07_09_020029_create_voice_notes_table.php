<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('related_type', ['class_plan', 'behavior_annotation', 'observation', 'citation']);
            $table->unsignedBigInteger('related_id');
            $table->string('field_name')->nullable();
            $table->string('audio_file_path');
            $table->integer('audio_duration_seconds');
            $table->enum('audio_format', ['webm', 'mp3', 'ogg'])->default('webm');
            $table->timestamps();

            $table->index(['related_type', 'related_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_notes');
    }
};
