<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2: exams. Rooms (with capacity, Putra or Putri), exam periods (UTS/UAS
 * per semester), one exam session per course offering and period, seat
 * numbers per session, and dispensations (rector's letter) for exam cards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->unsignedSmallInteger('capacity');
            // Exam seating keeps putra and putri in separate rooms (v2 D3).
            $table->string('gender', 10);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('exam_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('semester_id')->constrained()->restrictOnDelete();
            $table->string('type', 10); // uts | uas
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['semester_id', 'type']);
        });

        Schema::create('exam_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_offering_id')->constrained()->restrictOnDelete();
            $table->date('held_on');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->foreignId('invigilator_id')->nullable()->constrained('teachers')->restrictOnDelete();
            $table->timestamp('seats_assigned_at')->nullable();
            $table->timestamps();

            $table->unique(['exam_period_id', 'course_offering_id']);
            $table->index('course_offering_id');
            $table->index('invigilator_id');
        });

        Schema::create('exam_session_room', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();

            $table->unique(['exam_session_id', 'room_id']);
            $table->index('room_id');
        });

        Schema::create('exam_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('seat_number');
            $table->timestamps();

            $table->unique(['exam_session_id', 'course_enrollment_id']);
            $table->unique(['exam_session_id', 'room_id', 'seat_number']);
            $table->index('course_enrollment_id');
            $table->index('room_id');
        });

        Schema::create('exam_dispensations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->string('letter_number', 100);
            $table->date('letter_date');
            $table->text('note')->nullable();
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['exam_period_id', 'student_id']);
            $table->index('student_id');
            $table->index('granted_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_dispensations');
        Schema::dropIfExists('exam_seats');
        Schema::dropIfExists('exam_session_room');
        Schema::dropIfExists('exam_sessions');
        Schema::dropIfExists('exam_periods');
        Schema::dropIfExists('rooms');
    }
};
