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
 * @property bool $is_active
 */
#[Fillable(['name', 'frequency', 'is_active'])]
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
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<FeeRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(FeeRate::class);
    }
}
