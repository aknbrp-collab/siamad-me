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
 * @property int|null $grade_component_id
 * @property string $title
 * @property string|null $description
 * @property Carbon $opens_at
 * @property Carbon $closes_at
 * @property int|null $duration_minutes
 * @property int $max_attempts
 * @property bool $shuffle_questions
 * @property Carbon|null $published_at
 * @property-read CourseOffering $courseOffering
 * @property-read Collection<int, QuizQuestion> $questions
 * @property-read Collection<int, QuizAttempt> $attempts
 */
#[Fillable(['course_offering_id', 'grade_component_id', 'title', 'description', 'opens_at', 'closes_at', 'duration_minutes', 'max_attempts', 'shuffle_questions', 'published_at'])]
class Quiz extends Model
{
    use Auditable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'max_attempts' => 1,
        'shuffle_questions' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'duration_minutes' => 'integer',
            'max_attempts' => 'integer',
            'shuffle_questions' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function isOpen(): bool
    {
        return $this->isPublished() && now()->between($this->opens_at, $this->closes_at);
    }

    /**
     * @return BelongsTo<CourseOffering, $this>
     */
    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class);
    }

    /**
     * @return HasMany<QuizQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<QuizAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }
}
