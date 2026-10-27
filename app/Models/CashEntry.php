<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\CashEntryType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money in or out of one account that is not a tuition payment: other
 * income, an expense, or one half of a transfer between accounts. Never
 * deleted; a wrong entry is voided with a reason.
 *
 * @property int $id
 * @property string $number
 * @property int $cash_account_id
 * @property CashEntryType $type
 * @property string $direction in | out
 * @property Carbon $entry_date
 * @property int $amount
 * @property int|null $cash_category_id
 * @property string|null $party
 * @property string $description
 * @property string|null $proof_path
 * @property string|null $transfer_id
 * @property int $recorded_by
 * @property Carbon|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 * @property Carbon $created_at
 * @property-read CashAccount $account
 * @property-read CashCategory|null $category
 * @property-read User $recorder
 */
#[Fillable([
    'number', 'cash_account_id', 'type', 'direction', 'entry_date', 'amount', 'cash_category_id', 'party',
    'description', 'proof_path', 'transfer_id', 'recorded_by', 'voided_at', 'voided_by', 'void_reason',
])]
class CashEntry extends Model
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
            'type' => CashEntryType::class,
            'entry_date' => 'date',
            'amount' => 'integer',
            'voided_at' => 'datetime',
        ];
    }

    public function isVoid(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * The amount with its sign: positive in, negative out.
     */
    public function signedAmount(): int
    {
        return $this->direction === 'in' ? $this->amount : -$this->amount;
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function counting(Builder $query): void
    {
        $query->whereNull('voided_at');
    }

    /**
     * @return BelongsTo<CashAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    /**
     * @return BelongsTo<CashCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(CashCategory::class, 'cash_category_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
