<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Corrección de datos (sin cambios de esquema): `groups.student_count` solo lo
     * llenaba el seeder de demo, así que en grupos con estudiantes importados o
     * matriculados quedó en 0. Se recalcula una vez aquí; desde ahora lo mantiene
     * StudentGroupObserver. Solo actualiza ese contador, no toca ningún otro dato.
     */
    public function up(): void
    {
        DB::statement("
            UPDATE `groups` SET student_count = (
                SELECT COUNT(*) FROM student_groups
                INNER JOIN students ON students.id = student_groups.student_id
                WHERE student_groups.group_id = `groups`.id
                  AND student_groups.status = 'activo'
                  AND students.deleted_at IS NULL
            )
        ");
    }

    public function down(): void
    {
        // Nada que revertir: el contador recalculado es el valor correcto.
    }
};
