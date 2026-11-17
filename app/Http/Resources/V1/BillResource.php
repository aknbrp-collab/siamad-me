<?php

namespace App\Http\Resources\V1;

use App\Enums\BillStatus;
use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Setting;
use App\Support\ActiveRole;
use App\Support\ApiFiles;
use App\Support\BillFees;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A bill with its lines, fees and payments, as on "Tagihan saya": amounts in
 * rupiah (integers), receipts as signed PDF links, and what a transfer
 * confirmation may still be. Each fee is paid on its own, within its own
 * instalment limit (after-review D55); a payment lists the fees it paid.
 *
 * @mixin Bill
 */
class BillResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Bill $bill */
        $bill = $this->resource;
        $user = $request->user();
        $role = ActiveRole::get();

        // Payments the payer sees: all but rejected ones, which show only when they sent them (with the reason).
        $bill->loadMissing('payments.allocations.line');
        $payments = $bill->payments->filter(fn (Payment $payment) => $payment->status !== PaymentStatus::Rejected || $payment->source === PaymentSource::Payer);
        $fees = BillFees::of($bill);
        $open = (int) $fees->filter(fn (array $fee) => $fee['used'] < $fee['max'])->sum('left');
        $feeRows = $fees->map(fn (array $fee) => [
            /** The bill line id: the key of `allocations` when recording a payment. */
            'id' => $fee['line']->id,
            'fee_type_id' => $fee['line']->fee_type_id,
            'description' => $fee['line']->description,
            /** After this fee's share of the scholarship discount. */
            'due' => $fee['due'],
            /** Verified. */
            'paid' => $fee['paid'],
            /** Waiting for verification. */
            'pending' => $fee['pending'],
            'left' => $fee['left'],
            'instalments_used' => $fee['used'],
            'instalments_max' => $fee['max'],
            /** Months billed, for a monthly fee. */
            'months' => $fee['line']->months,
            /** Can it take another payment. */
            'payable' => $fee['left'] > 0 && $fee['used'] < $fee['max'],
        ])->values();

        return [
            'id' => $bill->id,
            'number' => $bill->number,
            'semester' => $bill->semester->label(),
            'due_on' => $bill->due_on?->toDateString(),
            'gross_amount' => $bill->gross_amount,
            'discount_amount' => $bill->discount_amount,
            'net_amount' => $bill->net_amount,
            /** Verified payments only. */
            'paid_amount' => $bill->paid_amount,
            'status' => $bill->status->value,
            'status_label' => $bill->status->label(),
            /** Every line, discounts (negative) included. */
            'lines' => $bill->lines->map(fn (BillLine $line) => ['id' => $line->id, 'description' => $line->description, 'amount' => $line->amount])->values()->all(),
            /** The fees (positive lines) with what is left of each and its instalments. */
            'fees' => $feeRows->all(),
            'payments' => $payments->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'paid_on' => $payment->paid_on->toDateString(),
                'amount' => $payment->amount,
                'method' => $payment->method->value,
                'method_label' => $payment->method->label(),
                'status' => $payment->status->value,
                'status_label' => $payment->status->label(),
                'status_note' => $payment->status === PaymentStatus::Rejected ? $payment->status_note : null,
                'receipt_url' => $payment->status === PaymentStatus::Verified ? ApiFiles::download($user, $role, 'receipt', ['payment' => $payment->id]) : null,
                /** The fees this payment paid. */
                'fees' => $payment->allocations->sortBy('bill_line_id')->map(fn (PaymentAllocation $allocation) => [
                    'id' => $allocation->bill_line_id,
                    'description' => $allocation->line->description,
                    'amount' => $allocation->amount,
                ])->values()->all(),
            ])->values()->all(),
            /**
             * For "Konfirmasi transfer": null when nothing is left to pay. The amount is spread over `fees` in
             * this order; a fee paid in part needs at least `min_amount` unless that settles it or (a monthly fee)
             * leaves it paid for whole months.
             */
            'transfer' => in_array($bill->status, [BillStatus::Paid, BillStatus::Void], true) || $open === 0 ? null : [
                'open_amount' => $open,
                'min_amount' => (int) Setting::get('payment.min_installment_amount', 200000),
                'fees' => $feeRows->where('payable', true)->map(fn (array $fee) => ['id' => $fee['id'], 'description' => $fee['description'], 'left' => $fee['left']])->values()->all(),
            ],
        ];
    }
}
