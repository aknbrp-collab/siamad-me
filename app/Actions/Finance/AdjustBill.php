<?php

namespace App\Actions\Finance;

use App\Enums\BillStatus;
use App\Enums\PaymentStatus;
use App\Models\Bill;
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
     * Superadmin only (checked by the caller): allow more (or fewer)
     * instalments on one bill. The reason is kept on the bill and audited.
     */
    public function setMaxInstallments(Bill $bill, int $max, string $reason): void
    {
        $used = $bill->payments()->whereIn('status', PaymentStatus::counting())->count();

        if ($max < max(1, $used) || $max > 12) {
            $this->fail('maxInstallments', __('Choose between :min and 12 (payments already recorded: :used).', ['min' => max(1, $used), 'used' => $used]));
        }

        if (trim($reason) === '') {
            $this->fail('overrideReason', __('Give a reason for changing the instalment limit.'));
        }

        DB::transaction(fn () => $bill->update([
            'max_installments' => $max,
            'status_note' => __('Instalment limit set to :max: :reason', ['max' => $max, 'reason' => trim($reason)]),
        ]));
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
