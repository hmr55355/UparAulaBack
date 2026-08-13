<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('students', function (Blueprint $table) {
                $table->string('document_type')->default('TI')->change();
            });

            return;
        }

        DB::statement("ALTER TABLE students MODIFY document_type ENUM('TI','CC','CE','PA','PPT') NOT NULL DEFAULT 'TI'");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('students', function (Blueprint $table) {
                $table->string('document_type')->default('TI')->change();
            });

            return;
        }

        DB::statement("ALTER TABLE students MODIFY document_type ENUM('TI','CC','CE','PA') NOT NULL DEFAULT 'TI'");
    }
};
