<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_citations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('parents')->nullOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('registered_by')->constrained('users');
            $table->foreignId('behavior_annotation_id')->nullable()->constrained('behavior_annotations')->nullOnDelete();
            $table->enum('citation_type', ['academica', 'comportamiento', 'seguimiento', 'entrega_boletin', 'general']);
            $table->text('reason');
            $table->dateTime('scheduled_date')->nullable();
            $table->string('location')->nullable();
            $table->enum('status', [
                'pendiente', 'notificado', 'confirmado', 'realizado', 'no_asistio', 'reprogramado',
            ])->default('pendiente');
            $table->enum('notification_method', ['celular', 'whatsapp', 'correo', 'agenda', 'otro'])->nullable();
            $table->dateTime('notification_date')->nullable();
            $table->text('outcome')->nullable();
            $table->text('commitments')->nullable();
            $table->date('follow_up_date')->nullable();
            $table->text('attachments_note')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_citations');
    }
};
