<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Monitores de curso: un estudiante al que el docente le crea un usuario para
 * tomar asistencia, registrar participaciones y anotar comportamiento en UN
 * curso. Nada de lo que hace el monitor cuenta hasta que el docente lo aprueba
 * (`monitor_submissions` guarda la propuesta; al aprobarla se aplica).
 *
 * Aditiva: columnas nuevas nulas/con default en `users` y `grade_columns`
 * (se amplía el enum con 'from_participation'); ningún dato existente cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 60)->nullable()->unique()->after('email');
            $table->string('account_type', 20)->default('teacher')->after('username'); // teacher | monitor
        });

        // El monitor entra con usuario y contraseña, sin correo.
        if ($sqlite) {
            Schema::table('users', fn (Blueprint $table) => $table->string('email')->nullable()->change());
            Schema::table('grade_columns', fn (Blueprint $table) => $table->string('column_type', 30)->change());
        } else {
            DB::statement('ALTER TABLE users MODIFY email VARCHAR(255) NULL');
            DB::statement("ALTER TABLE grade_columns MODIFY column_type ENUM('manual','from_attendance','section_average','custom_formula','from_participation') NOT NULL");
        }

        Schema::create('course_monitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // cuenta del monitor
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['group_subject_id', 'user_id']);
        });

        Schema::create('monitor_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_monitor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submitted_by')->constrained('users');
            $table->string('type', 20); // attendance | behavior | participation
            $table->json('payload');
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
            $table->index(['group_subject_id', 'status']);
        });

        Schema::create('participations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedSmallInteger('points')->default(1);
            $table->string('notes')->nullable();
            $table->foreignId('registered_by')->constrained('users');
            $table->foreignId('monitor_submission_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['group_subject_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participations');
        Schema::dropIfExists('monitor_submissions');
        Schema::dropIfExists('course_monitors');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'account_type']);
        });
    }
};
