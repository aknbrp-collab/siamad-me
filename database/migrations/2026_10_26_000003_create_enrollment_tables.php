<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('class_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('semester_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_group_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->string('class_name');
            $table->timestamps();

            $table->unique(['student_id', 'semester_id']);
            $table->index(['semester_id', 'class_group_id']);
        });

        Schema::create('course_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('semester_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_group_id')->constrained()->restrictOnDelete();
            $table->foreignId('teacher_id')->constrained()->restrictOnDelete();
            $table->string('subject_code', 20);
            $table->string('subject_name');
            $table->unsignedTinyInteger('credits');
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->string('room', 50)->nullable();
            $table->timestamps();

            $table->unique(['semester_id', 'subject_id', 'class_group_id']);
            $table->index(['semester_id', 'teacher_id']);
        });

        Schema::create('course_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->decimal('final_score', 5, 2)->nullable();
            $table->string('final_letter', 3)->nullable();
            $table->decimal('final_point', 3, 2)->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique(['course_offering_id', 'student_id']);
            $table->index('student_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('course_enrollments');
        Schema::dropIfExists('course_offerings');
        Schema::dropIfExists('class_enrollments');
    }
};
