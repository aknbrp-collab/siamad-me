<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Actions\Finance\ReviewPayments;
use App\Enums\HandoverStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentHandover;
use App\Support\ActiveRole;
use App\Support\ApiFiles;
use App\Support\PendingPayments;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Verifying payments. Cash is handed over and counted; transfers are checked
 * against the bank statement. Whoever recorded a payment never verifies it:
 * the finance office checks what mahasantri, wali and the Petugas Pembayaran
 * sent; an Admin checks what the finance office recorded.
 */
#[Group('Keuangan', weight: 16)]
class PaymentController extends Controller
{
    /**
     * My cash to hand over.
     *
     * Cash payments I recorded that are not handed over yet, and my handovers (latest 15) with their status.
     */
    public function myCash(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'cash' => Payment::query()->with('bill.student.user')
                ->where('recorded_by', $user->id)->where('method', PaymentMethod::Cash)->where('status', PaymentStatus::Unverified)->whereNull('payment_handover_id')
                ->orderBy('paid_on')->get()
                ->map(fn (Payment $payment) => $this->payment($request, $payment))->values()->all(),
            'handovers' => PaymentHandover::query()->with('receivedBy')->withCount('payments')->where('handed_over_by', $user->id)->orderByDesc('id')->limit(15)->get()
                ->map(fn (PaymentHandover $handover) => [
                    ...$this->handoverSummary($handover),
                    'received_by' => $handover->receivedBy?->name,
                    'payments_count' => (int) $handover->getAttribute('payments_count'),
                ])->values()->all(),
        ]]);
    }

    /**
     * Hand cash over.
     *
     * `payment_ids` (my unverified cash), `note`. The receiver is notified; a provisional slip prints on the web.
     */
    public function handOver(Request $request, ReviewPayments $review): JsonResponse
    {
        $request->validate(['payment_ids' => ['required', 'array'], 'payment_ids.*' => ['integer'], 'note' => ['nullable', 'string', 'max:500']]);
        $handover = $review->handOver($request->user(), array_values(array_map('intval', (array) $request->input('payment_ids'))), $request->input('note') ?: null);

        return response()->json(['data' => $this->handoverSummary($handover)], 201);
    }

    /**
     * To verify.
     *
     * Handovers waiting to be counted and transfers waiting to be checked that this role verifies (the finance
     * office: from mahasantri, wali and the Petugas Pembayaran; Admin: from the finance office).
     */
    public function queue(Request $request): JsonResponse
    {
        $role = ActiveRole::get();
        $reviews = fn (PaymentSource $source) => in_array($role, [Role::Admin, Role::Superadmin], true)
            ? ($role === Role::Superadmin || $source === PaymentSource::Staff)
            : $source !== PaymentSource::Staff;

        $handovers = PaymentHandover::query()->with(['handedOverBy', 'payments.bill.student.user'])->where('status', HandoverStatus::Submitted)->orderBy('submitted_at')->get()
            ->filter(fn (PaymentHandover $handover) => ($first = $handover->payments->first()) !== null && $reviews($first->source));
        $transfers = Payment::query()->with(['bill.student.user', 'bill.semester.academicYear', 'recorder'])
            ->where('method', PaymentMethod::Transfer)->where('status', PaymentStatus::Unverified)->orderBy('created_at')->get()
            ->filter(fn (Payment $payment) => $reviews($payment->source));

        return response()->json(['data' => [
            'handovers' => $handovers->map(fn (PaymentHandover $handover) => [
                ...$this->handoverSummary($handover),
                'handed_over_by' => $handover->handedOverBy->name,
                'payments' => $handover->payments->map(fn (Payment $payment) => $this->payment($request, $payment))->values()->all(),
            ])->values()->all(),
            'transfers' => $transfers->map(fn (Payment $payment) => $this->payment($request, $payment))->values()->all(),
        ]]);
    }

    /**
     * Count a handover.
     *
     * Every payment is verified except those in `rejections` {payment id: reason} (money not received);
     * accepted cash goes into `cash_account_id` (default: the cash account set as default).
     */
    public function completeHandover(Request $request, PaymentHandover $handover, ReviewPayments $review): Response
    {
        $request->validate(['rejections' => ['nullable', 'array'], 'cash_account_id' => ['nullable', 'integer']]);
        abort_unless($review->mayReceive($handover, $request->user()), 403);

        $rejections = collect((array) $request->input('rejections', []))->mapWithKeys(fn ($reason, $id) => [(int) $id => (string) $reason])->all();
        $review->completeHandover($handover, $request->user(), $rejections, $request->integer('cash_account_id') ?: null);

        return response()->noContent();
    }

    /**
     * Verify or reject a transfer.
     *
     * `verify: true` (into `cash_account_id`, default the bank account set as default), or `false` with a
     * `reason` the payer sees.
     */
    public function reviewTransfer(Request $request, Payment $payment, ReviewPayments $review): Response
    {
        $request->validate(['verify' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:500'], 'cash_account_id' => ['nullable', 'integer']]);

        $request->boolean('verify')
            ? $review->verifyTransfer($payment, $request->user(), $request->integer('cash_account_id') ?: null)
            : $review->rejectTransfer($payment, $request->user(), (string) $request->input('reason'));

        return response()->noContent();
    }

    /**
     * Unverified payments.
     *
     * Every payment not verified yet, at which stage it waits (cash held, handover waiting, transfer waiting),
     * with whom and for how many days, and a summary per holder. `stage` filters.
     */
    public function pending(Request $request): JsonResponse
    {
        $request->validate(['stage' => ['nullable', Rule::in(array_keys(PendingPayments::stages()))]]);
        $rows = PendingPayments::rows();

        return response()->json(['data' => [
            'summary' => PendingPayments::summary($rows)->values()->all(),
            'payments' => ($request->filled('stage') ? $rows->where('stage', (string) $request->input('stage')) : $rows)->values()
                ->map(fn (array $row) => [
                    ...$this->payment($request, $row['payment']),
                    'stage' => $row['stage'],
                    'from' => $row['from']->name,
                    'holder' => $row['holder'],
                    'holder_key' => PendingPayments::holderKey($row),
                    'days' => $row['days'],
                ])->all(),
        ]]);
    }

    /**
     * Remind a holder.
     *
     * `holder_key` from the summary; at most once a day per holder. Returns how many people were reminded.
     */
    public function remind(Request $request): JsonResponse
    {
        $request->validate(['holder_key' => ['required', 'string']]);

        return response()->json(['data' => ['reminded' => PendingPayments::remind((string) $request->input('holder_key'), $request->user())]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payment(Request $request, Payment $payment): array
    {
        $payment->loadMissing(['bill.student.user', 'bill.semester.academicYear']);

        return [
            'id' => $payment->id,
            'bill_id' => $payment->bill_id,
            'bill_number' => $payment->bill->number,
            'nim' => $payment->bill->student->nim,
            'name' => $payment->bill->student->user->name,
            'semester' => $payment->bill->semester->label(),
            'amount' => $payment->amount,
            'method' => $payment->method->value,
            'source' => $payment->source->value,
            'paid_on' => $payment->paid_on->toDateString(),
            'reference' => $payment->reference,
            'status' => $payment->status->value,
            'proof_url' => $payment->proof_path === null ? null : ApiFiles::url($request->user(), ActiveRole::get(), 'payment-proof', $payment->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function handoverSummary(PaymentHandover $handover): array
    {
        return [
            'id' => $handover->id,
            'number' => $handover->number,
            'total_amount' => $handover->total_amount,
            'status' => $handover->status->value,
            'status_label' => $handover->status->label(),
            'note' => $handover->note,
            'submitted_at' => $handover->submitted_at->toIso8601String(),
            'reviewed_at' => $handover->reviewed_at?->toIso8601String(),
        ];
    }
}
