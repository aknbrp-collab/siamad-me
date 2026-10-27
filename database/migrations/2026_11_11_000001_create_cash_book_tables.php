<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v3 Step 5: the cash book (D8). Accounts (Kas, bank), income and expense
 * categories, and entries for everything that is not a tuition payment:
 * other income, expenses, transfers between accounts. Verified tuition
 * payments are income of the account chosen on the payment (D9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('type', 10); // cash | bank
            $table->string('account_number', 50)->nullable();
            $table->bigInteger('opening_balance')->default(0);
            $table->date('opening_date');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cash_categories', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10); // income | expense
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['kind', 'name']);
        });

        $now = now();
        foreach ([
            'income' => ['Infaq dan donasi', 'Hibah', 'Lain-lain'],
            'expense' => ['Honorarium', 'Alat tulis kantor', 'Listrik, air dan internet', 'Konsumsi', 'Perawatan gedung', 'Transportasi', 'Lain-lain'],
        ] as $kind => $names) {
            foreach ($names as $name) {
                DB::table('cash_categories')->insert(['kind' => $kind, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        Schema::create('cash_entries', function (Blueprint $table) {
            $table->id();
            // BKM-… (income), BKK-… (expense), MUT-… (transfer, both halves).
            $table->string('number', 30)->index();
            $table->foreignId('cash_account_id')->index()->constrained()->restrictOnDelete();
            $table->string('type', 10); // income | expense | transfer
            $table->string('direction', 3); // in | out
            $table->date('entry_date')->index();
            $table->unsignedBigInteger('amount');
            $table->foreignId('cash_category_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->string('party', 150)->nullable(); // received from / paid to
            $table->string('description', 500);
            $table->string('proof_path')->nullable();
            // The two halves of a transfer share it.
            $table->uuid('transfer_id')->nullable()->index();
            $table->foreignId('recorded_by')->index()->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('cash_account_id')->nullable()->index()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_account_id');
        });

        Schema::dropIfExists('cash_entries');
        Schema::dropIfExists('cash_categories');
        Schema::dropIfExists('cash_accounts');
    }
};
