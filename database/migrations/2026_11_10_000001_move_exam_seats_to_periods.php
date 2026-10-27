<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v3 Step 4 (D7): one room and seat per mahasantri for the whole exam period,
 * instead of one per exam. Rooms move from each exam to the period (the
 * union of the rooms its exams used); per-exam seats are dropped and the
 * period goes back to draft, so it is shuffled once more before publishing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_period_room', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->index()->constrained()->restrictOnDelete();

            $table->unique(['exam_period_id', 'room_id']);
        });

        $rooms = DB::table('exam_session_room')
            ->join('exam_sessions', 'exam_sessions.id', '=', 'exam_session_room.exam_session_id')
            ->select('exam_sessions.exam_period_id', 'exam_session_room.room_id')
            ->distinct()
            ->get();

        foreach ($rooms as $room) {
            DB::table('exam_period_room')->insert(['exam_period_id' => $room->exam_period_id, 'room_id' => $room->room_id]);
        }

        $seated = DB::table('exam_sessions')->whereNotNull('seats_assigned_at')->distinct()->pluck('exam_period_id');
        DB::table('exam_periods')->whereIn('id', $seated)->update(['published_at' => null]);

        Schema::drop('exam_seats');
        Schema::drop('exam_session_room');

        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->dropColumn('seats_assigned_at');
        });

        Schema::table('exam_periods', function (Blueprint $table) {
            $table->timestamp('seats_assigned_at')->nullable();
        });

        Schema::create('exam_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('room_id')->index()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('seat_number');
            $table->timestamps();

            $table->unique(['exam_period_id', 'student_id']);
            $table->unique(['exam_period_id', 'room_id', 'seat_number']);
        });
    }

    public function down(): void
    {
        Schema::drop('exam_seats');

        Schema::table('exam_periods', function (Blueprint $table) {
            $table->dropColumn('seats_assigned_at');
        });

        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->timestamp('seats_assigned_at')->nullable();
        });

        Schema::create('exam_session_room', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->index()->constrained()->restrictOnDelete();

            $table->unique(['exam_session_id', 'room_id']);
        });

        Schema::create('exam_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_enrollment_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('room_id')->index()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('seat_number');
            $table->timestamps();

            $table->unique(['exam_session_id', 'course_enrollment_id']);
            $table->unique(['exam_session_id', 'room_id', 'seat_number']);
        });

        Schema::drop('exam_period_room');
    }
};
