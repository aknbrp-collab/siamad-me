<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\ScholarshipType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $academic_year_id
 * @property string $name
 * @property string $funding_source
 * @property ScholarshipType $type
 * @property int $value Percent (1–100) or rupiah, depending on the type.
 * @property int|null $quota Maximum number of mahasantri; null is unlimited.
 * @property bool $is_active
 * @property-read AcademicYear $academicYear
 */
#[Fillable(['academic_year_id', 'name', 'funding_source', 'type', 'value', 'quota', 'is_active'])]
class Scholarship extends Model
{
    use Auditable;

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
            'type' => ScholarshipType::class,
            'value' => 'integer',
            'quota' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The discount this scholarship gives on a bill of the given gross amount.
     */
    public function discountFor(int $gross): int
    {
        $discount = match ($this->type) {
            ScholarshipType::Full => $gross,
            ScholarshipType::Percent => intdiv($gross * min($this->value, 100), 100),
            ScholarshipType::Fixed => $this->value,
        };

        return max(0, min($discount, $gross));
    }

    public function valueLabel(): string
    {
        return match ($this->type) {
            ScholarshipType::Full => __('100%'),
            ScholarshipType::Percent => $this->value.'%',
            ScholarshipType::Fixed => Money::format($this->value),
        };
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * @return HasMany<ScholarshipAward, $this>
     */
    public function awards(): HasMany
    {
        return $this->hasMany(ScholarshipAward::class);
    }
}
