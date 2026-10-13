<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El módulo de "copias" pasa a ser de pagos en general: cada cobro tiene un
 * concepto (copias, materiales, colaboración, salida pedagógica…). Los cobros
 * que ya existían eran todos de copias. La tabla conserva su nombre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('copy_charges', function (Blueprint $table) {
            // Texto, no enum: la lista vive en CopyCharge::CONCEPTS y puede crecer.
            $table->string('concept', 30)->default('copias')->after('registered_by');
        });
    }

    public function down(): void
    {
        Schema::table('copy_charges', fn (Blueprint $table) => $table->dropColumn('concept'));
    }
};
