<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v3: who signs printed documents (with an optional signature image) and the
 * stamps that may go with them. Several of each, since offices sign and
 * stamp differently (Mudir + institution stamp, Bendahara + finance stamp).
 * Rows are deactivated, not deleted, so old prints stay explainable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signatories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            // Printed under the name, e.g. "Mudir", "Bendahara".
            $table->string('position', 150);
            // e.g. "Pimpinan", "Bagian Keuangan"; only to tell entries apart.
            $table->string('office', 150)->nullable();
            $table->string('signature_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('stamps', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('image_path');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stamps');
        Schema::dropIfExists('signatories');
    }
};
