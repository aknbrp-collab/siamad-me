<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * @property-read Bill $bill
 * @property-read ScholarshipAward|null $scholarshipAward
 */
#[Fillable(['bill_id', 'fee_type_id', 'scholarship_award_id', 'description', 'amount'])]
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
