<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per fee (after-review D55, D56): how many instalments each fee may be paid in, and for a monthly fee how many
 * months a semester bills. A bill line keeps both as they were when the bill was made. Existing fees get the
 * current payment.max_installments and 6 months, so nothing changes until the finance office edits them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $stored = DB::table('settings')->where('key', 'payment.max_installments')->value('value');
        $instalments = (int) ($stored !== null ? json_decode((string) $stored, true) : (config('siakad.settings')['payment.max_installments'] ?? 3)) ?: 3;

        Schema::table('fee_types', function (Blueprint $table) use ($instalments) {
            $table->unsignedTinyInteger('max_installments')->default($instalments)->after('frequency');
            $table->unsignedTinyInteger('months_per_semester')->default(6)->after('max_installments');
        });

        Schema::table('bill_lines', function (Blueprint $table) {
            // Null on discount lines and archive lines (no fee).
            $table->unsignedTinyInteger('max_installments')->nullable()->after('amount');
            $table->unsignedTinyInteger('months')->nullable()->after('max_installments');
        });

        // Lines of existing bills: the bill's own limit, and 6 months for monthly fees.
        DB::table('bill_lines')->whereNotNull('fee_type_id')->orderBy('id')->each(function (object $line) {
            $bill = DB::table('bills')->where('id', $line->bill_id)->first(['max_installments']);
            $monthly = DB::table('fee_types')->where('id', $line->fee_type_id)->value('frequency') === 'monthly';

            DB::table('bill_lines')->where('id', $line->id)->update([
                'max_installments' => $bill->max_installments ?? null,
                'months' => $monthly ? 6 : null,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('bill_lines', function (Blueprint $table) {
            $table->dropColumn(['max_installments', 'months']);
        });

        Schema::table('fee_types', function (Blueprint $table) {
            $table->dropColumn(['max_installments', 'months_per_semester']);
        });
    }
};
