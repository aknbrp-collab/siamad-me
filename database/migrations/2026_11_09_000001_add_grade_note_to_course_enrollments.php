<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v3: where a final grade came from when it was not finalised in SIAKAD, e.g.
 * "Imported from the 2025/2026 archive" or the reason for a correction made in
 * maintenance mode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_enrollments', function (Blueprint $table) {
            $table->string('grade_note', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('course_enrollments', function (Blueprint $table) {
            $table->dropColumn('grade_note');
        });
    }
};
