<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1: a muhadhir is pembimbing akademik (academic supervisor) of a group of
 * mahasantri. One open supervision per mahasantri; changing the supervisor
 * closes the old row (history kept, never overwritten). The group has its own
 * forum: a forum thread belongs to a course offering or to a supervisor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supervisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('teacher_id')->constrained()->restrictOnDelete();
            $table->date('started_on');
            $table->date('ended_on')->nullable();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // PostgreSQL does not index foreign keys itself (see 2026_10_30_000001).
            $table->index(['teacher_id', 'ended_on']);
            $table->index('student_id');
            $table->index('assigned_by');
        });

        DB::statement('create unique index supervisions_one_open_per_student on supervisions (student_id) where ended_on is null');

        Schema::table('forum_threads', function (Blueprint $table) {
            $table->foreignId('course_offering_id')->nullable()->change();
            $table->foreignId('supervisor_id')->nullable()->after('course_offering_id')
                ->constrained('teachers')->cascadeOnDelete();
            $table->index('supervisor_id');
        });
    }

    public function down(): void
    {
        Schema::table('forum_threads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supervisor_id');
            $table->foreignId('course_offering_id')->nullable(false)->change();
        });

        Schema::dropIfExists('supervisions');
    }
};
