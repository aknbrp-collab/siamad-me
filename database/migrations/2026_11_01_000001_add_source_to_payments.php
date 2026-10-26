<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1: mahasantri and wali santri can confirm a bank transfer themselves.
 * `source` tells those (payer) apart from payments recorded by the finance
 * office (staff); existing payments were all recorded by staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('source', 10)->default('staff')->after('method');
            $table->index(['source', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['source', 'status']);
            $table->dropColumn('source');
        });
    }
};
