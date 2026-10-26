<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A mahasantri's answer to an assignment. Resubmitting replaces it until it
 * is graded.
 *
 * @property int $id
 * @property int $assignment_id
 * @property int $course_enrollment_id
 * @property string|null $text
 * @property string|null $file_path
 * @property string|null $original_name
 * @property Carbon $submitted_at
 * @property bool $is_late
 * @property string|null $score
 * @property string|null $feedback
 * @property Carbon|null $graded_at
 * @property int|null $graded_by
 * @property-read Assignment $assignment
 * @property-read CourseEnrollment $courseEnrollment
 */
#[Fillable(['assignment_id', 'course_enrollment_id', 'text', 'file_path', 'original_name', 'submitted_at', 'is_late', 'score', 'feedback', 'graded_at', 'graded_by'])]
class Submission extends Model
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
            'submitted_at' => 'datetime',
            'is_late' => 'boolean',
            'score' => 'decimal:2',
            'graded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Assignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /**
     * @return BelongsTo<CourseEnrollment, $this>
     */
    public function courseEnrollment(): BelongsTo
    {
        return $this->belongsTo(CourseEnrollment::class);
    }
}
