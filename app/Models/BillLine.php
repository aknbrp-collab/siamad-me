<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A fee (positive) or scholarship discount (negative) on a bill. The
 * description and amount are snapshots.
 *
 * @property int $id
 * @property int $bill_id
 * @property int|null $fee_type_id
 * @property int|null $scholarship_award_id
 * @property string $description
 * @property int $amount
 * @property int|null $max_installments The fee's instalment limit when the bill was made (null: no fee)
 * @property int|null $months Months billed, for a monthly fee
 * @property-read Bill $bill
 * @property-read ScholarshipAward|null $scholarshipAward
 */
#[Fillable(['bill_id', 'fee_type_id', 'scholarship_award_id', 'description', 'amount', 'max_installments', 'months'])]
class BillLine extends Model
{
    use Auditable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * @return BelongsTo<Bill, $this>
     */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    /**
     * @return BelongsTo<ScholarshipAward, $this>
     */
    public function scholarshipAward(): BelongsTo
    {
        return $this->belongsTo(ScholarshipAward::class);
    }
}
