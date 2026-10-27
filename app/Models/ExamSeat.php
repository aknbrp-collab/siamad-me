<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A mahasantri's room and seat number in one exam session.
 *
 * @property int $id
 * @property int $exam_session_id
 * @property int $course_enrollment_id
 * @property int $room_id
 * @property int $seat_number
 * @property-read ExamSession $session
 * @property-read CourseEnrollment $courseEnrollment
 * @property-read Room $room
 */
#[Fillable(['exam_session_id', 'course_enrollment_id', 'room_id', 'seat_number'])]
class ExamSeat extends Model
{
    /**
     * @return BelongsTo<ExamSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'exam_session_id');
    }

    /**
     * @return BelongsTo<CourseEnrollment, $this>
     */
    public function courseEnrollment(): BelongsTo
    {
        return $this->belongsTo(CourseEnrollment::class);
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
