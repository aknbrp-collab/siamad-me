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
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('nim', 32)->unique();
            $table->foreignId('study_program_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('entry_year');
            $table->string('gender', 10);
            $table->string('birth_place')->nullable();
            $table->date('birth_date')->nullable();
            $table->text('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('photo_path')->nullable();
            $table->string('father_name')->nullable();
            $table->boolean('father_is_alive')->nullable();
            $table->string('mother_name')->nullable();
            $table->boolean('mother_is_alive')->nullable();
            $table->string('guardian_name')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->date('graduated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('teachers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('nidm', 32)->unique();
            $table->string('nik', 16)->nullable()->unique();
            $table->string('title')->nullable();
            $table->string('gender', 10);
            $table->string('birth_place')->nullable();
            $table->date('birth_date')->nullable();
            $table->text('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('photo_path')->nullable();
            $table->string('expertise')->nullable();
            $table->string('scopus_id', 50)->nullable();
            $table->string('google_scholar_url')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('guardians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('code', 20)->unique();
            $table->string('relationship', 50)->nullable();
            $table->string('phone', 30)->nullable();
            $table->timestamps();
        });

        Schema::create('guardian_student', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guardian_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['guardian_id', 'student_id']);
        });

        foreach (['student', 'teacher'] as $owner) {
            Schema::create("{$owner}_documents", function (Blueprint $table) use ($owner) {
                $table->id();
                $table->foreignId("{$owner}_id")->constrained()->cascadeOnDelete();
                $table->string('type', 20);
                $table->string('file_path')->nullable();
                $table->string('original_name')->nullable();
                $table->string('status', 20)->default('missing')->index();
                $table->timestamp('uploaded_at')->nullable();
                $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('verified_at')->nullable();
                $table->text('rejection_note')->nullable();
                $table->timestamps();

                $table->unique(["{$owner}_id", 'type']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teacher_documents');
        Schema::dropIfExists('student_documents');
        Schema::dropIfExists('guardian_student');
        Schema::dropIfExists('guardians');
        Schema::dropIfExists('teachers');
        Schema::dropIfExists('students');
    }
};
