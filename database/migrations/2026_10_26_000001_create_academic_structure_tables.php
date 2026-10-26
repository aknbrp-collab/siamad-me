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
        Schema::create('study_programs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->string('name', 20)->unique();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();
        });

        Schema::create('semesters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->string('term', 10);
            $table->string('status', 10)->default('planned');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->text('status_note')->nullable();
            $table->timestamps();

            $table->unique(['academic_year_id', 'term']);
        });

        // At most one active semester at any time.
        DB::statement("create unique index semesters_single_active on semesters (status) where status = 'active'");

        Schema::create('class_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_program_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('level');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_program_id')->constrained()->restrictOnDelete();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('kitab')->nullable();
            $table->unsignedTinyInteger('credits');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('class_groups');
        Schema::dropIfExists('semesters');
        Schema::dropIfExists('academic_years');
        Schema::dropIfExists('study_programs');
    }
};
