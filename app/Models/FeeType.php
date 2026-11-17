<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\FeeFrequency;
use Database\Factories\FeeTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property FeeFrequency $frequency
 * @property int $max_installments How many payments the fee may be paid in (after-review D55)
 * @property int $months_per_semester Months billed a semester for a monthly fee (D56)
 * @property bool $is_active
 */
#[Fillable(['name', 'frequency', 'max_installments', 'months_per_semester', 'is_active'])]
class FeeType extends Model
{
    /** @use HasFactory<FeeTypeFactory> */
    use Auditable, HasFactory;

    /**
     * Matches the column default, so new instances are active before saving.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'frequency' => FeeFrequency::class,
            'max_installments' => 'integer',
            'months_per_semester' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * How many times the rate is billed in a semester: its months for a monthly fee, else once.
     */
    public function monthsBilled(): int
    {
        return $this->frequency === FeeFrequency::Monthly ? max(1, $this->months_per_semester) : 1;
    }

    /**
     * @return HasMany<FeeRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(FeeRate::class);
    }
}
