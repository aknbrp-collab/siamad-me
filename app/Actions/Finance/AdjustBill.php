<?php

namespace App\Actions\Finance;

use App\Enums\BillStatus;
use App\Enums\PaymentStatus;
use App\Models\Bill;
use App\Models\BillLine;
use App\Support\BillFees;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdjustBill
{
    /**
     * Void a bill that has no pending or verified payments (e.g. generated
     * with wrong rates) so it can be generated again. Never for a closed semester.
     */
    public function void(Bill $bill, string $reason): void
    {
        $bill->loadMissing('semester')->semester->assertOpen();

        if ($bill->status === BillStatus::Void) {
            $this->fail('bill', __('This bill is already void.'));
        }

        if ($bill->payments()->whereIn('status', PaymentStatus::counting())->exists()) {
            $this->fail('bill', __('This bill has payments; it cannot be voided.'));
        }

        if (trim($reason) === '') {
            $this->fail('voidReason', __('Give a reason for voiding the bill.'));
        }

        $bill->update(['status' => BillStatus::Void, 'status_note' => trim($reason)]);
    }

    /**
     * Superadmin only (checked by the caller): allow more (or fewer) instalments on one fee of a bill, or on
     * every fee of it (after-review D55). Never fewer than the payments that fee already has. The reason is kept
     * on the bill and audited.
     */
    public function setMaxInstallments(Bill $bill, int $max, string $reason, ?BillLine $line = null): void
    {
        $fees = BillFees::of($bill);
        abort_if($line !== null && ! $fees->has($line->id), 404);
        $chosen = $line !== null ? $fees->only([$line->id]) : $fees;
        $used = (int) $chosen->max('used');

        if ($max < max(1, $used) || $max > 12) {
            $this->fail('maxInstallments', __('Choose between :min and 12 (payments already recorded: :used).', ['min' => max(1, $used), 'used' => $used]));
        }

        if (trim($reason) === '') {
            $this->fail('overrideReason', __('Give a reason for changing the instalment limit.'));
        }

        DB::transaction(function () use ($bill, $chosen, $line, $max, $reason) {
            foreach ($chosen as $fee) {
                $fee['line']->update(['max_installments' => $max]);
            }

            $bill->update([
                // The bill's own limit is what lines without one (archive lines) follow.
                'max_installments' => $line === null ? $max : $bill->max_installments,
                'status_note' => $line === null
                    ? __('Instalment limit set to :max: :reason', ['max' => $max, 'reason' => trim($reason)])
                    : __('Instalment limit of :fee set to :max: :reason', ['fee' => $line->description, 'max' => $max, 'reason' => trim($reason)]),
            ]);
        });
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
