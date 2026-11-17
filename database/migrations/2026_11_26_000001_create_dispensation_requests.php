<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Surat permohonan dispensasi (v7 D47): a mahasantri whose bill of the semester is not paid off writes
 * why and prints the request for the Mudir to sign. One per mahasantri and semester; writing again
 * replaces the reason. Admin grants it as an exam dispensation once the Mudir has signed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispensation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained()->restrictOnDelete();
            $table->text('reason');
            // Still to pay over all bills when the request was written (for the Admin's list).
            $table->unsignedBigInteger('outstanding')->default(0);
            $table->timestamps();

            $table->unique(['student_id', 'semester_id']);
            $table->index('semester_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispensation_requests');
    }
};
