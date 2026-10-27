<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The exam of one course offering in an exam period: date, time, rooms,
 * invigilator, and the seats once assigned.
 *
 * @property int $id
 * @property int $exam_period_id
 * @property int $course_offering_id
 * @property Carbon $held_on
 * @property string $starts_at
 * @property string $ends_at
 * @property int|null $invigilator_id
 * @property Carbon|null $seats_assigned_at
 * @property-read ExamPeriod $period
 * @property-read CourseOffering $courseOffering
 * @property-read Teacher|null $invigilator
 * @property-read Collection<int, Room> $rooms
 * @property-read Collection<int, ExamSeat> $seats
 */
#[Fillable(['exam_period_id', 'course_offering_id', 'held_on', 'starts_at', 'ends_at', 'invigilator_id', 'seats_assigned_at'])]
class ExamSession extends Model
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
            'held_on' => 'date',
            'seats_assigned_at' => 'datetime',
        ];
    }

    public function timeLabel(): string
    {
        return $this->held_on->translatedFormat('l, j M Y').' '.substr($this->starts_at, 0, 5).'–'.substr($this->ends_at, 0, 5);
    }

    /**
     * @return BelongsTo<ExamPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(ExamPeriod::class, 'exam_period_id');
    }

    /**
     * @return BelongsTo<CourseOffering, $this>
     */
    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class);
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function invigilator(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'invigilator_id');
    }

    /**
     * @return BelongsToMany<Room, $this>
     */
    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class, 'exam_session_room');
    }

    /**
     * @return HasMany<ExamSeat, $this>
     */
    public function seats(): HasMany
    {
        return $this->hasMany(ExamSeat::class);
    }
}
