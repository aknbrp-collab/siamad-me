<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\CashAccountType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Where money is kept: the cash box (kas) or a bank account. The opening
 * balance is the amount at the start of the books (opening_date).
 *
 * @property int $id
 * @property string $name
 * @property CashAccountType $type
 * @property string|null $account_number
 * @property int $opening_balance
 * @property Carbon $opening_date
 * @property bool $is_active
 */
#[Fillable(['name', 'type', 'account_number', 'opening_balance', 'opening_date', 'is_active'])]
class CashAccount extends Model
{
    use Auditable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'opening_balance' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CashAccountType::class,
            'opening_balance' => 'integer',
            'opening_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function label(): string
    {
        return $this->name.($this->account_number ? ' · '.$this->account_number : '');
    }

    /**
     * @return HasMany<CashEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(CashEntry::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
