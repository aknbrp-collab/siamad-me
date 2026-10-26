<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $course_offering_id
 * @property int|null $grade_component_id Scores can be copied into this grade component.
 * @property string $title
 * @property string|null $instructions
 * @property Carbon $due_at
 * @property bool $allow_late
 * @property int $max_score
 * @property-read CourseOffering $courseOffering
 * @property-read GradeComponent|null $gradeComponent
 * @property-read Collection<int, Submission> $submissions
 */
#[Fillable(['course_offering_id', 'grade_component_id', 'title', 'instructions', 'due_at', 'allow_late', 'max_score'])]
class Assignment extends Model
{
    use Auditable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'allow_late' => false,
        'max_score' => 100,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'allow_late' => 'boolean',
            'max_score' => 'integer',
        ];
    }

    public function isPastDue(): bool
    {
        return now()->greaterThan($this->due_at);
    }

    /**
     * @return BelongsTo<CourseOffering, $this>
     */
    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class);
    }

    /**
     * @return BelongsTo<GradeComponent, $this>
     */
    public function gradeComponent(): BelongsTo
    {
        return $this->belongsTo(GradeComponent::class);
    }

    /**
     * @return HasMany<Submission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }
}
