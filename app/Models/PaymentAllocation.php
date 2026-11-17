<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of a payment went to one fee (bill line) of the bill (after-review D55).
 *
 * @property int $id
 * @property int $payment_id
 * @property int $bill_line_id
 * @property int $amount
 * @property-read Payment $payment
 * @property-read BillLine $line
 */
#[Fillable(['payment_id', 'bill_line_id', 'amount'])]
class PaymentAllocation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<BillLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(BillLine::class, 'bill_line_id');
    }
}
