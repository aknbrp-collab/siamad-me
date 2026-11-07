<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Muhadhir evaluation (v6 D35), like EDOM: each semester the Admin sets the
 * questions and the period; mahasantri rate every class kuliah they follow.
 *
 * Answers are anonymous by design: scores are kept only as counts per
 * (class, question, score), comments have no author or time, and the
 * "done" marker (student × class) has no id or time to line it up with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('semester_id')->unique()->constrained()->restrictOnDelete();
            $table->date('opens_on');
            $table->date('closes_on');
            // The closing open question, e.g. "Saran untuk muhadhir".
            $table->string('comment_prompt', 255);
            // Mahasantri see their KHS of this semester only after answering (while open).
            $table->boolean('required_for_khs')->default(false);
            $table->timestamps();
        });

        Schema::create('evaluation_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('semester_id')->constrained()->restrictOnDelete();
            $table->string('group', 100);
            $table->string('question', 500);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['semester_id', 'position']);
        });

        Schema::create('evaluation_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->restrictOnDelete();
            $table->foreignId('evaluation_question_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('score');
            $table->unsignedInteger('count')->default(0);

            $table->unique(['course_offering_id', 'evaluation_question_id', 'score'], 'evaluation_scores_unique');
            $table->index('evaluation_question_id');
        });

        Schema::create('evaluation_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->restrictOnDelete();
            $table->text('body');

            $table->index('course_offering_id');
        });

        Schema::create('evaluation_submissions', function (Blueprint $table) {
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('course_offering_id')->constrained()->restrictOnDelete();

            $table->primary(['student_id', 'course_offering_id']);
            $table->index('course_offering_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_submissions');
        Schema::dropIfExists('evaluation_comments');
        Schema::dropIfExists('evaluation_scores');
        Schema::dropIfExists('evaluation_questions');
        Schema::dropIfExists('evaluation_periods');
    }
};
