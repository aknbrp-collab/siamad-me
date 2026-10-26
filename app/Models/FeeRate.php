<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The amount of a fee type for one academic year, optionally narrowed to a
 * class and/or an entry year (angkatan). The most specific match wins.
 *
 * @property int $id
 * @property int $fee_type_id
 * @property int $academic_year_id
 * @property int|null $class_group_id
 * @property int|null $entry_year
 * @property int $amount
 * @property-read FeeType $feeType
 * @property-read ClassGroup|null $classGroup
 */
#[Fillable(['fee_type_id', 'academic_year_id', 'class_group_id', 'entry_year', 'amount'])]
class FeeRate extends Model
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
            'entry_year' => 'integer',
        ];
    }

    /**
     * Higher is more specific: class + entry year > class > entry year > everyone.
     */
    public function specificity(): int
    {
        return ($this->class_group_id !== null ? 2 : 0) + ($this->entry_year !== null ? 1 : 0);
    }

    /**
     * @return BelongsTo<FeeType, $this>
     */
    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FeeType::class);
    }

    /**
     * @return BelongsTo<ClassGroup, $this>
     */
    public function classGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class)->withTrashed();
    }
}
