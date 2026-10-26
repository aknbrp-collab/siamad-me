<?php

namespace App\Actions\Finance;

use App\Enums\PaymentMethod;
use App\Enums\PaymentSource;
use App\Models\Bill;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

/**
 * A mahasantri or wali santri confirms a bank transfer they made, with the
 * bank receipt. It becomes an unverified transfer on the bill, under the same
 * instalment rules as payments the finance office records, and counts only
 * after Admin Keuangan or Admin verifies it.
 */
class ConfirmTransfer
{
    public function __construct(private readonly RecordPayment $recordPayment) {}

    /**
     * Whether $user may pay this bill: their own (mahasantri) or a linked child's (wali santri).
     */
    public function canPay(User $user, Bill $bill): bool
    {
        if ($user->student?->id === $bill->student_id) {
            return true;
        }

        return $user->guardian?->students()->whereKey($bill->student_id)->exists() ?? false;
    }

    /**
     * @param  array<string, mixed>  $input  amount, paid_on, reference, proof
     */
    public function handle(Bill $bill, User $payer, array $input): Payment
    {
        abort_unless($this->canPay($payer, $bill), 403);

        // The receipt is what the finance office checks against the bank statement.
        Validator::make($input, [
            'reference' => ['required', 'string', 'max:255'],
            'proof' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:2048'],
        ], [
            'reference.required' => __('Enter the sender name or bank reference of the transfer.'),
            'proof.required' => __('Attach the bank receipt (photo or PDF).'),
        ])->validate();

        return $this->recordPayment->handle(
            $bill,
            $payer,
            [...$input, 'method' => PaymentMethod::Transfer->value],
            PaymentSource::Payer,
        );
    }
}
