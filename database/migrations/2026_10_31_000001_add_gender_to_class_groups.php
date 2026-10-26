<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1: classes are Putra or Putri, and `level` now means the semester of study
 * (Semester I, II, III …) instead of the year. Existing classes keep their
 * level number and get no gender: an admin sets it before placing anyone new,
 * and past enrolments are never rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_groups', function (Blueprint $table) {
            $table->string('gender', 10)->nullable()->after('level');
        });
    }

    public function down(): void
    {
        Schema::table('class_groups', function (Blueprint $table) {
            $table->dropColumn('gender');
        });
    }
};
