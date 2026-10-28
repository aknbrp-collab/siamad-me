<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v3 Step 7: muhadhir teaching documents (D13–D15). Class offerings taught
 * together point to one lead offering (joined_to_id); the RPS and journal
 * recap belong to the lead, every upload a new version, reviewed by Admin.
 * Meetings get journal fields (jurnal mengajar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_offerings', function (Blueprint $table) {
            $table->foreignId('joined_to_id')->nullable()->index()->constrained('course_offerings')->restrictOnDelete();
        });

        Schema::table('class_sessions', function (Blueprint $table) {
            $table->text('material')->nullable();
            $table->string('method', 255)->nullable();
            $table->text('journal_note')->nullable();
        });

        Schema::create('teaching_documents', function (Blueprint $table) {
            $table->id();
            // The lead offering of the class or joint group.
            $table->foreignId('course_offering_id')->constrained()->restrictOnDelete();
            $table->string('kind', 10); // rps | journal
            $table->unsignedSmallInteger('version');
            $table->string('file_path');
            $table->string('original_name');
            $table->foreignId('uploaded_by')->index()->constrained('users')->restrictOnDelete();
            $table->string('status', 10)->default('waiting')->index(); // waiting | accepted | returned
            $table->foreignId('reviewed_by')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->unique(['course_offering_id', 'kind', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teaching_documents');

        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropColumn(['material', 'method', 'journal_note']);
        });

        Schema::table('course_offerings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('joined_to_id');
        });
    }
};
