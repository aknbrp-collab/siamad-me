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
 * One try at a quiz. The deadline is fixed on the server when the attempt
 * starts; the timer in the browser is only a display.
 *
 * @property int $id
 * @property int $quiz_id
 * @property int $course_enrollment_id
 * @property Carbon $started_at
 * @property Carbon $deadline_at
 * @property Carbon|null $submitted_at
 * @property string|null $score
 * @property int $max_score
 * @property bool $needs_manual_grading
 * @property list<int> $question_order
 * @property-read Quiz $quiz
 * @property-read CourseEnrollment $courseEnrollment
 * @property-read Collection<int, QuizAnswer> $answers
 */
#[Fillable(['quiz_id', 'course_enrollment_id', 'started_at', 'deadline_at', 'submitted_at', 'score', 'max_score', 'needs_manual_grading', 'question_order'])]
class QuizAttempt extends Model
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
            'started_at' => 'datetime',
            'deadline_at' => 'datetime',
            'submitted_at' => 'datetime',
            'score' => 'decimal:2',
            'max_score' => 'integer',
            'needs_manual_grading' => 'boolean',
            'question_order' => 'array',
        ];
    }

    public function isInProgress(): bool
    {
        return $this->submitted_at === null && now()->lessThan($this->deadline_at);
    }

    /**
     * Score as a percentage (0–100), once graded.
     */
    public function percent(): ?float
    {
        return $this->score === null || $this->needs_manual_grading || $this->max_score === 0
            ? null
            : round((float) $this->score / $this->max_score * 100, 2);
    }

    /**
     * @return BelongsTo<Quiz, $this>
     */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    /**
     * @return BelongsTo<CourseEnrollment, $this>
     */
    public function courseEnrollment(): BelongsTo
    {
        return $this->belongsTo(CourseEnrollment::class);
    }

    /**
     * @return HasMany<QuizAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(QuizAnswer::class);
    }
}
