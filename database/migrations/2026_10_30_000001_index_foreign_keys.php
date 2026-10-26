<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PostgreSQL does not index foreign keys by itself. Without these, lookups
 * such as "payments of a bill" or "posts of a thread", and every restricted
 * delete of a parent row, scan the whole child table. Foreign keys already
 * leading a unique or composite index are not repeated here.
 */
return new class extends Migration
{
    /**
     * @var array<string, list<string>>
     */
    private const COLUMNS = [
        'activity_logs' => ['recorded_by'],
        'assignments' => ['course_offering_id', 'grade_component_id'],
        'audit_logs' => ['user_id'],
        'bill_lines' => ['bill_id', 'fee_type_id', 'scholarship_award_id'],
        'bills' => ['student_id'],
        'class_enrollments' => ['class_group_id'],
        'class_groups' => ['study_program_id'],
        'course_offerings' => ['class_group_id', 'grades_finalized_by', 'subject_id', 'teacher_id'],
        'fee_rates' => ['class_group_id', 'fee_type_id'],
        'forum_posts' => ['forum_thread_id', 'user_id'],
        'forum_threads' => ['course_offering_id', 'user_id'],
        'grade_scores' => ['grade_component_id'],
        'guardian_student' => ['student_id'],
        'materials' => ['course_offering_id'],
        'payment_handovers' => ['handed_over_by', 'received_by'],
        'payments' => ['bill_id', 'payment_handover_id', 'recorded_by', 'verified_by'],
        'quiz_answers' => ['quiz_question_id'],
        'quiz_attempts' => ['course_enrollment_id'],
        'quiz_options' => ['quiz_question_id'],
        'quiz_questions' => ['quiz_id'],
        'quizzes' => ['course_offering_id', 'grade_component_id'],
        'scholarship_awards' => ['approved_by', 'semester_id', 'student_id'],
        'scholarships' => ['academic_year_id'],
        'student_documents' => ['verified_by'],
        'students' => ['study_program_id'],
        'subjects' => ['study_program_id'],
        'submissions' => ['course_enrollment_id', 'graded_by'],
        'teacher_documents' => ['verified_by'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $column) {
                    $blueprint->index($column);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $column) {
                    $blueprint->dropIndex([$column]);
                }
            });
        }
    }
};
