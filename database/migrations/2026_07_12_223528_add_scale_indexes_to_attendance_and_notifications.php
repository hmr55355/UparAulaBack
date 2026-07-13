<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * attendance_records solo tenía el índice único (student_id, group_subject_id,
     * date) — las consultas por rango de fecha que filtran solo por
     * group_subject_id (AttendanceController::index/stats) no pueden usar ese
     * índice eficientemente por el orden de sus columnas. notifications no tenía
     * ningún índice compuesto para la consulta paginada por usuario+fecha ya
     * existente en NotificationController::index.
     */
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->index(['group_subject_id', 'date']);
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropIndex(['group_subject_id', 'date']);
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'created_at']);
        });
    }
};
