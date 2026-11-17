<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payments per fee (after-review D55): how much of a payment went to which fee of the bill. Existing payments
 * that count (verified or waiting) are spread over their bill's fees in the bill's order, oldest payment first,
 * each fee up to what it is due after its share of the scholarship discount; amounts paid do not change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bill_line_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->timestamps();

            $table->unique(['payment_id', 'bill_line_id']);
            $table->index('bill_line_id');
        });

        $now = now();

        DB::table('bills')->orderBy('id')->each(function (object $bill) use ($now) {
            $lines = DB::table('bill_lines')->where('bill_id', $bill->id)->orderBy('id')->get(['id', 'amount']);
            $fees = $lines->filter(fn (object $line) => $line->amount > 0)->values();

            if ($fees->isEmpty()) {
                return;
            }

            // Each fee's share of the discount, in proportion to its amount (largest remainder, exact sum).
            $discount = (int) -$lines->filter(fn (object $line) => $line->amount < 0)->sum('amount');
            $gross = (int) $fees->sum('amount');
            $left = [];
            $shares = [];
            $remainders = [];
            foreach ($fees as $fee) {
                $exact = $gross > 0 ? $discount * $fee->amount / $gross : 0;
                $shares[$fee->id] = (int) floor($exact);
                $remainders[$fee->id] = $exact - floor($exact);
            }
            $missing = $discount - array_sum($shares);
            arsort($remainders);
            foreach (array_keys($remainders) as $id) {
                if ($missing-- <= 0) {
                    break;
                }
                $shares[$id]++;
            }
            foreach ($fees as $fee) {
                $left[$fee->id] = max(0, $fee->amount - $shares[$fee->id]);
            }

            $payments = DB::table('payments')->where('bill_id', $bill->id)->whereIn('status', ['verified', 'unverified'])
                ->orderBy('paid_on')->orderBy('id')->get(['id', 'amount']);

            foreach ($payments as $payment) {
                $rest = (int) $payment->amount;
                $rows = [];

                foreach ($left as $lineId => $open) {
                    if ($rest <= 0) {
                        break;
                    }
                    $take = min($rest, $open);
                    if ($take > 0) {
                        $rows[$lineId] = $take;
                        $left[$lineId] -= $take;
                        $rest -= $take;
                    }
                }

                // More than every fee was due (an old overpayment): the rest stays with the last fee.
                if ($rest > 0) {
                    $last = array_key_last($left);
                    $rows[$last] = ($rows[$last] ?? 0) + $rest;
                }

                foreach ($rows as $lineId => $amount) {
                    DB::table('payment_allocations')->insert(['payment_id' => $payment->id, 'bill_line_id' => $lineId, 'amount' => $amount, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
