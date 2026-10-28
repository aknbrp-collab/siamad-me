<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Classes move up (v4 D20). A class created from now on is a cohort: it starts
 * at a level in its entry semester and goes up one level each semester; its
 * name is built as "Semester {roman} {label}". Classes that exist today keep
 * their fixed name and level and can be used up to the current semester only
 * ("start fresh"). The class name and level of every placement and offering
 * are kept as they were in that semester.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_groups', function (Blueprint $table) {
            $table->string('label', 100)->nullable()->after('name');
            $table->foreignId('entry_semester_id')->nullable()->after('level')->constrained('semesters')->nullOnDelete();
            $table->unsignedTinyInteger('start_level')->nullable()->after('entry_semester_id');
            $table->foreignId('last_semester_id')->nullable()->after('start_level')->constrained('semesters')->nullOnDelete();
            $table->index('entry_semester_id');
            $table->index('last_semester_id');
        });

        Schema::table('class_enrollments', function (Blueprint $table) {
            $table->unsignedTinyInteger('level')->nullable()->after('class_name');
        });

        Schema::table('course_offerings', function (Blueprint $table) {
            $table->string('class_name', 100)->nullable()->after('class_group_id');
        });

        // Today's classes end with the current semester (the active one, else the latest).
        $current = DB::table('semesters')->where('status', 'active')->value('id')
            ?? DB::table('semesters')->join('academic_years', 'academic_years.id', '=', 'semesters.academic_year_id')
                ->orderByDesc('academic_years.starts_on')->orderByRaw("case when semesters.term = 'odd' then 1 else 2 end desc")->value('semesters.id');

        foreach (DB::table('class_groups')->get(['id', 'name', 'level']) as $class) {
            DB::table('class_groups')->where('id', $class->id)->update(['last_semester_id' => $current]);
            DB::table('class_enrollments')->where('class_group_id', $class->id)->update(['level' => $class->level]);
            DB::table('course_offerings')->where('class_group_id', $class->id)->update(['class_name' => $class->name]);
        }
    }

    public function down(): void
    {
        Schema::table('course_offerings', fn (Blueprint $table) => $table->dropColumn('class_name'));
        Schema::table('class_enrollments', fn (Blueprint $table) => $table->dropColumn('level'));
        Schema::table('class_groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('entry_semester_id');
            $table->dropConstrainedForeignId('last_semester_id');
            $table->dropColumn(['label', 'start_level']);
        });
    }
};
