<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('grade_scales', function (Blueprint $table) {
            $table->id();
            $table->string('letter', 3)->unique();
            $table->decimal('min_score', 5, 2);
            $table->decimal('point', 3, 2);
            $table->timestamps();
        });

        // Default scale; Admin can change it. Finalised grades keep their own
        // letter and point, so changing the scale never rewrites history.
        $now = now();
        DB::table('grade_scales')->insert(array_map(fn (array $row) => [
            'letter' => $row[0], 'min_score' => $row[1], 'point' => $row[2], 'created_at' => $now, 'updated_at' => $now,
        ], [
            ['A', 85, 4.00], ['B+', 80, 3.50], ['B', 70, 3.00], ['C+', 65, 2.50],
            ['C', 55, 2.00], ['D', 40, 1.00], ['E', 0, 0.00],
        ]));

        Schema::create('grade_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->unsignedTinyInteger('weight');
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['course_offering_id', 'name']);
        });

        Schema::create('grade_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grade_component_id')->constrained()->cascadeOnDelete();
            $table->decimal('score', 5, 2);
            $table->timestamps();

            $table->unique(['course_enrollment_id', 'grade_component_id']);
        });

        Schema::table('course_offerings', function (Blueprint $table) {
            $table->timestamp('grades_finalized_at')->nullable();
            $table->foreignId('grades_finalized_by')->nullable()->constrained('users')->nullOnDelete();
            // Why grades were unlocked after finalisation (kept and audited).
            $table->text('grades_note')->nullable();
        });

        Schema::create('class_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('meeting_no');
            $table->date('held_on');
            $table->string('topic')->nullable();
            $table->string('mode', 10)->default('offline');
            $table->string('meeting_provider', 20)->nullable();
            $table->string('meeting_url')->nullable();
            $table->timestamp('attendance_taken_at')->nullable();
            $table->timestamps();

            $table->unique(['course_offering_id', 'meeting_no']);
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_enrollment_id')->constrained()->cascadeOnDelete();
            $table->string('status', 10);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['class_session_id', 'course_enrollment_id']);
            $table->index('course_enrollment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('class_sessions');

        Schema::table('course_offerings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('grades_finalized_by');
            $table->dropColumn(['grades_finalized_at', 'grades_note']);
        });

        Schema::dropIfExists('grade_scores');
        Schema::dropIfExists('grade_components');
        Schema::dropIfExists('grade_scales');
    }
};
