<?php

namespace App\Actions\Finance;

use App\Enums\HandoverStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Bill;
use App\Models\Payment;
use App\Models\PaymentHandover;
use App\Models\User;
use App\Support\Money;
use App\Support\Notify;
use App\Support\NumberSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The money flow after recording (design §5C):
 * Admin Payment hands cash over in a batch → an Admin verifies or rejects.
 * Transfers are verified one by one against the bank statement.
 * The person who recorded a payment can never verify it.
 */
class ReviewPayments
{
    /**
     * Bundle the recorder's own pending cash payments into a handover (setoran).
     *
     * @param  list<int>  $paymentIds
     */
    public function handOver(User $recorder, array $paymentIds, ?string $note = null): PaymentHandover
    {
        if ($paymentIds === []) {
            $this->fail('handover', __('Choose at least one cash payment.'));
        }

        return DB::transaction(function () use ($recorder, $paymentIds, $note) {
            $payments = Payment::query()->whereKey($paymentIds)->lockForUpdate()->get();

            $eligible = $payments->every(fn (Payment $payment) => $payment->status === PaymentStatus::Unverified
                && $payment->method === PaymentMethod::Cash
                && $payment->recorded_by === $recorder->id
                && $payment->payment_handover_id === null);

            if ($payments->count() !== count(array_unique($paymentIds)) || ! $eligible) {
                $this->fail('handover', __('Only your own pending cash payments that are not handed over yet can be included.'));
            }

            $handover = PaymentHandover::create([
                'number' => NumberSequence::next('STR'),
                'handed_over_by' => $recorder->id,
                'total_amount' => (int) $payments->sum('amount'),
                'status' => HandoverStatus::Submitted,
                'note' => $note,
                'submitted_at' => now(),
            ]);

            Payment::query()->whereKey($payments->modelKeys())->update(['payment_handover_id' => $handover->id]);

            return $handover;
        });
    }

    /**
     * The Admin counts the money of a handover: every payment is verified
     * except the ones listed in $rejections (payment id => reason).
     *
     * @param  array<int, string>  $rejections
     */
    public function completeHandover(PaymentHandover $handover, User $verifier, array $rejections = []): void
    {
        if ($handover->status !== HandoverStatus::Submitted) {
            $this->fail('handover', __('This handover was already reviewed.'));
        }

        if ($handover->handed_over_by === $verifier->id) {
            $this->fail('handover', __('You handed this money over; another person must receive and verify it.'));
        }

        foreach ($rejections as $reason) {
            if (trim($reason) === '') {
                $this->fail('handover', __('Give a reason for each rejected payment.'));
            }
        }

        DB::transaction(function () use ($handover, $verifier, $rejections) {
            foreach ($handover->payments()->get() as $payment) {
                if (array_key_exists($payment->id, $rejections)) {
                    $this->reject($payment, $verifier, $rejections[$payment->id]);
                } else {
                    $this->confirm($payment, $verifier);
                }
            }

            $rejected = count($rejections);
            $total = $handover->payments()->count();

            $handover->update([
                'status' => match (true) {
                    $rejected === 0 => HandoverStatus::Verified,
                    $rejected >= $total => HandoverStatus::Rejected,
                    default => HandoverStatus::PartiallyRejected,
                },
                'received_by' => $verifier->id,
                'reviewed_at' => now(),
            ]);
        });
    }

    /**
     * Verify a bank transfer on its own. Cash goes through a handover.
     */
    public function verifyTransfer(Payment $payment, User $verifier): void
    {
        if ($payment->method !== PaymentMethod::Transfer) {
            $this->fail('payment', __('Cash payments are verified through a handover.'));
        }

        $this->ensureMayReviewTransfer($payment, $verifier);

        DB::transaction(fn () => $this->confirm($payment, $verifier));

        $this->notifyPayer($payment, __('Transfer verified'), __(':amount for bill :number is verified. The official receipt is ready.', [
            'amount' => Money::format($payment->amount),
            'number' => $payment->bill->number,
        ]), 'check-badge');
    }

    public function rejectTransfer(Payment $payment, User $verifier, string $reason): void
    {
        if ($payment->method !== PaymentMethod::Transfer) {
            $this->fail('payment', __('Cash payments are reviewed through a handover.'));
        }

        $this->ensureMayReviewTransfer($payment, $verifier);

        if (trim($reason) === '') {
            $this->fail('rejectionReason', __('Give a reason for rejecting the payment.'));
        }

        DB::transaction(fn () => $this->reject($payment, $verifier, $reason));

        $this->notifyPayer($payment, __('Transfer rejected'), __(':amount for bill :number: :reason', [
            'amount' => Money::format($payment->amount),
            'number' => $payment->bill->number,
            'reason' => trim($reason),
        ]), 'x-circle');
    }

    /**
     * Tell the mahasantri / wali who confirmed a transfer how it was reviewed.
     */
    private function notifyPayer(Payment $payment, string $title, string $body, string $icon): void
    {
        if ($payment->source !== PaymentSource::Payer) {
            return;
        }

        $payer = $payment->loadMissing(['recorder.student', 'bill'])->recorder;

        Notify::send([$payer], 'payment', $title, $body, route($payer->student ? 'mahasantri.bills' : 'wali.bills'), $icon);
    }

    /**
     * Superadmin only (checked by the caller): undo a verified payment, e.g.
     * a bounced transfer. The receipt number stays, marked void.
     */
    public function void(Payment $payment, string $reason): void
    {
        if ($payment->status !== PaymentStatus::Verified) {
            $this->fail('payment', __('Only verified payments can be voided.'));
        }

        if (trim($reason) === '') {
            $this->fail('voidReason', __('Give a reason for voiding the payment.'));
        }

        DB::transaction(function () use ($payment, $reason) {
            $payment->update(['status' => PaymentStatus::Void, 'status_note' => trim($reason)]);
            $this->lockedBill($payment)->recalculate();
        });
    }

    private function confirm(Payment $payment, User $verifier): void
    {
        $this->ensureReviewable($payment, $verifier);

        $bill = $this->lockedBill($payment);
        $verifiedSoFar = (int) $bill->payments()->where('status', PaymentStatus::Verified)->sum('amount');

        if ($verifiedSoFar + $payment->amount > $bill->net_amount) {
            $this->fail('payment', __('Verifying :amount would overpay bill :number.', [
                'amount' => Money::format($payment->amount),
                'number' => $bill->number,
            ]));
        }

        $payment->update([
            'status' => PaymentStatus::Verified,
            'verified_by' => $verifier->id,
            'verified_at' => now(),
            'receipt_number' => NumberSequence::next('KW'),
        ]);

        $bill->recalculate();
    }

    private function reject(Payment $payment, User $verifier, string $reason): void
    {
        $this->ensureReviewable($payment, $verifier);

        $payment->update([
            'status' => PaymentStatus::Rejected,
            'verified_by' => $verifier->id,
            'verified_at' => now(),
            'status_note' => trim($reason),
        ]);
    }

    private function ensureReviewable(Payment $payment, User $verifier): void
    {
        $payment->refresh();

        if ($payment->status !== PaymentStatus::Unverified) {
            $this->fail('payment', __('This payment was already reviewed.'));
        }

        if ($payment->recorded_by === $verifier->id) {
            $this->fail('payment', __('You recorded this payment; another person must verify it.'));
        }
    }

    /**
     * Transfers the finance office recorded are verified by an Admin (the
     * recorder is Admin Keuangan). Transfers a mahasantri or wali confirmed
     * were recorded by no staff member, so Admin Keuangan may verify them too.
     */
    public function mayReviewTransfer(Payment $payment, User $verifier): bool
    {
        $roles = $payment->source === PaymentSource::Payer
            ? [Role::Admin, Role::Superadmin, Role::AdminPayment]
            : [Role::Admin, Role::Superadmin];

        return $verifier->hasRole(...$roles);
    }

    private function ensureMayReviewTransfer(Payment $payment, User $verifier): void
    {
        if (! $this->mayReviewTransfer($payment, $verifier)) {
            $this->fail('payment', __('Only an Admin can verify transfers recorded by the finance office.'));
        }
    }

    private function lockedBill(Payment $payment): Bill
    {
        return Bill::query()->lockForUpdate()->findOrFail($payment->bill_id);
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
