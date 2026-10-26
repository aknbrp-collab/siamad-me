<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2: a role can be limited to a scope. Petugas Pembayaran (payment
 * collector) works only on the bills of putra or putri mahasantri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_roles', function (Blueprint $table) {
            $table->string('scope', 10)->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('user_roles', function (Blueprint $table) {
            $table->dropColumn('scope');
        });
    }
};
