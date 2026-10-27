<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A mahasantri's room and seat number for every exam of one exam period.
 *
 * @property int $id
 * @property int $exam_period_id
 * @property int $student_id
 * @property int $room_id
 * @property int $seat_number
 * @property-read ExamPeriod $period
 * @property-read Student $student
 * @property-read Room $room
 */
#[Fillable(['exam_period_id', 'student_id', 'room_id', 'seat_number'])]
class ExamSeat extends Model
{
    /**
     * @return BelongsTo<ExamPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(ExamPeriod::class, 'exam_period_id');
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
