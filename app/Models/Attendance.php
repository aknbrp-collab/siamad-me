<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\AttendanceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $class_session_id
 * @property int $course_enrollment_id
 * @property AttendanceStatus $status
 * @property string|null $note
 * @property-read ClassSession $classSession
 * @property-read CourseEnrollment $courseEnrollment
 */
#[Fillable(['class_session_id', 'course_enrollment_id', 'status', 'note'])]
class Attendance extends Model
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
            'status' => AttendanceStatus::class,
        ];
    }

    /**
     * @return BelongsTo<ClassSession, $this>
     */
    public function classSession(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class);
    }

    /**
     * @return BelongsTo<CourseEnrollment, $this>
     */
    public function courseEnrollment(): BelongsTo
    {
        return $this->belongsTo(CourseEnrollment::class);
    }
}
