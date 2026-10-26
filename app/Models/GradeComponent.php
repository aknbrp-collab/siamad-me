<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A weighted part of an offering's grade (Tugas, UTS, UAS, Hafalan…).
 *
 * @property int $id
 * @property int $course_offering_id
 * @property string $name
 * @property int $weight Percent; an offering's weights add up to 100.
 * @property int $position
 */
#[Fillable(['course_offering_id', 'name', 'weight', 'position'])]
class GradeComponent extends Model
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
            'weight' => 'integer',
            'position' => 'integer',
        ];
    }

    /**
     * @return HasMany<GradeScore, $this>
     */
    public function scores(): HasMany
    {
        return $this->hasMany(GradeScore::class);
    }

    /**
     * @return BelongsTo<CourseOffering, $this>
     */
    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class);
    }
}
