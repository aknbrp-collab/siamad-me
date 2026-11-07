<?php

namespace App\Http\Resources\V1;

use App\Enums\BillStatus;
use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\Payment;
use App\Models\Setting;
use App\Support\ActiveRole;
use App\Support\ApiFiles;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A bill with its lines and payments, as on "Tagihan saya": amounts in
 * rupiah (integers), receipts as signed PDF links, and what a transfer
 * confirmation may still be.
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
        $payments = $bill->payments->filter(fn (Payment $payment) => $payment->status !== PaymentStatus::Rejected || $payment->source === PaymentSource::Payer);
        $counting = $bill->payments->filter(fn (Payment $payment) => in_array($payment->status, PaymentStatus::counting(), true));
        $open = max(0, $bill->net_amount - (int) $counting->sum('amount'));

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
            'lines' => $bill->lines->map(fn (BillLine $line) => ['description' => $line->description, 'amount' => $line->amount])->values()->all(),
            'payments' => $payments->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'paid_on' => $payment->paid_on->toDateString(),
                'amount' => $payment->amount,
                'method' => $payment->method->value,
                'method_label' => $payment->method->label(),
                'status' => $payment->status->value,
                'status_label' => $payment->status->label(),
                'status_note' => $payment->status === PaymentStatus::Rejected ? $payment->status_note : null,
                'receipt_url' => $payment->status === PaymentStatus::Verified ? ApiFiles::pdf($user, $role, 'receipt', ['payment' => $payment->id]) : null,
            ])->values()->all(),
            /** For "Konfirmasi transfer": null when nothing is left to pay. */
            'transfer' => in_array($bill->status, [BillStatus::Paid, BillStatus::Void], true) || $open === 0 ? null : [
                'open_amount' => $open,
                'instalments_used' => $counting->count(),
                'instalments_max' => $bill->max_installments,
                'min_amount' => (int) Setting::get('payment.min_installment_amount', 200000),
            ],
        ];
    }
}
