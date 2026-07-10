<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_copy_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('copy_charge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('registered_by')->constrained('users');
            $table->enum('status', ['debe', 'pago_parcial', 'pagado', 'exonerado'])->default('debe');
            $table->decimal('amount_paid', 8, 2)->default(0);
            $table->date('payment_date')->nullable();
            $table->text('exoneration_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['copy_charge_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_copy_payments');
    }
};
