<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. All money is whole rupiah in bigint columns.
     */
    public function up(): void
    {
        // Gap-free running numbers for bills, handovers and receipts.
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->unsignedBigInteger('last_value')->default(0);
        });

        Schema::create('fee_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('frequency', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('fee_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_group_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('entry_year')->nullable();
            $table->unsignedBigInteger('amount');
            $table->timestamps();

            $table->index(['academic_year_id', 'fee_type_id']);
        });

        Schema::create('scholarships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('funding_source');
            $table->string('type', 10);
            $table->unsignedBigInteger('value')->default(0);
            $table->unsignedInteger('quota')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('scholarship_awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scholarship_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('semester_id')->constrained()->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['scholarship_id', 'student_id', 'semester_id']);
        });

        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('semester_id')->constrained()->restrictOnDelete();
            $table->string('number', 30)->unique();
            $table->date('due_on')->nullable();
            $table->unsignedBigInteger('gross_amount');
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('net_amount');
            $table->unsignedBigInteger('paid_amount')->default(0);
            $table->unsignedTinyInteger('max_installments');
            $table->string('status', 10)->default('unpaid')->index();
            $table->text('status_note')->nullable();
            $table->timestamps();

            $table->index(['semester_id', 'status']);
        });

        // One live (non-void) bill per student per semester.
        DB::statement("create unique index bills_one_live_per_semester on bills (student_id, semester_id) where status <> 'void'");

        Schema::create('bill_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('scholarship_award_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description');
            $table->bigInteger('amount');
            $table->timestamps();
        });

        Schema::create('payment_handovers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('handed_over_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('total_amount');
            $table->string('status', 20)->default('submitted')->index();
            $table->text('note')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('method', 10);
            $table->date('paid_on');
            $table->string('reference')->nullable();
            $table->string('proof_path')->nullable();
            $table->string('status', 12)->default('unverified')->index();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('status_note')->nullable();
            $table->foreignId('payment_handover_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('receipt_number', 30)->nullable()->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('payment_handovers');
        Schema::dropIfExists('bill_lines');
        Schema::dropIfExists('bills');
        Schema::dropIfExists('scholarship_awards');
        Schema::dropIfExists('scholarships');
        Schema::dropIfExists('fee_rates');
        Schema::dropIfExists('fee_types');
        Schema::dropIfExists('number_sequences');
    }
};
