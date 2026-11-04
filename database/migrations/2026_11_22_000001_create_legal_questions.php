<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tanya Hukum (v5 D30, spec §4.1 and §3.4): questions forwarded by the CMS,
 * discussed in LPPPK, answered by a muhadhir and sent back after two other
 * muhadhir approve. Every answer version is kept; a correction is a new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_questions', function (Blueprint $table) {
            $table->id();
            $table->string('cms_question_id', 191)->unique();
            $table->string('tracking_code', 50)->nullable();
            $table->string('category', 20);
            $table->text('question');
            $table->string('asker_name', 255)->nullable();
            $table->string('asker_city', 255)->nullable();
            $table->boolean('anonymous')->default(false);
            $table->timestamp('submitted_at')->nullable();
            $table->text('moderator_note')->nullable();
            $table->string('callback_url', 2048);
            $table->foreignId('discussion_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('discussion_id');
        });

        Schema::create('legal_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_question_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->longText('answer_md');
            $table->text('references')->nullable();
            $table->foreignId('drafted_by')->constrained('users')->restrictOnDelete();
            // draft | awaiting (approval) | approved (being sent) | sent | superseded
            $table->string('status', 12)->default('draft')->index();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            // What the CMS answered: answered | published, and whether it holds this as a correction.
            $table->string('cms_status', 20)->nullable();
            $table->boolean('correction_pending')->default(false);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['legal_question_id', 'version']);
            $table->index('drafted_by');
        });

        Schema::create('legal_answer_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_answer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approver_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['legal_answer_id', 'approver_id']);
            $table->index('approver_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_answer_approvals');
        Schema::dropIfExists('legal_answers');
        Schema::dropIfExists('legal_questions');
    }
};
