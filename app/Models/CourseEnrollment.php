<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $course_offering_id
 * @property int $student_id
 * @property string|null $final_score
 * @property string|null $final_letter
 * @property string|null $final_point
 * @property Carbon|null $finalized_at
 * @property-read CourseOffering $courseOffering
 * @property-read Student $student
 */
#[Fillable(['course_offering_id', 'student_id', 'final_score', 'final_letter', 'final_point', 'finalized_at'])]
class CourseEnrollment extends Model
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
            'final_score' => 'decimal:2',
            'final_point' => 'decimal:2',
            'finalized_at' => 'datetime',
        ];
    }

    /**
     * Whether teaching records exist (scores, attendance, a final grade,
     * submissions or quiz attempts);
     * such rows are never removed automatically.
     */
    public function hasGradeData(): bool
    {
        return $this->final_score !== null
            || $this->finalized_at !== null
            || $this->scores()->exists()
            || $this->attendances()->exists()
            || $this->submissions()->exists()
            || $this->quizAttempts()->exists();
    }

    /**
     * Enrolments that carry teaching records (see hasGradeData()).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withRecords(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereNotNull('final_score')
            ->orWhereNotNull('finalized_at')
            ->orWhereHas('scores')
            ->orWhereHas('attendances')
            ->orWhereHas('submissions')
            ->orWhereHas('quizAttempts'));
    }

    /**
     * @return BelongsTo<CourseOffering, $this>
     */
    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class);
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return HasMany<GradeScore, $this>
     */
    public function scores(): HasMany
    {
        return $this->hasMany(GradeScore::class);
    }

    /**
     * @return HasMany<Attendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * @return HasMany<Submission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    /**
     * @return HasMany<QuizAttempt, $this>
     */
    public function quizAttempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }
}
