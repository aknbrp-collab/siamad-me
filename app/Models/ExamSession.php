<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The exam of one course offering in an exam period: date, time and
 * invigilator. Its mahasantri sit in their period seats (ExamSeat), so the
 * rooms of an exam follow from where they sit.
 *
 * @property int $id
 * @property int $exam_period_id
 * @property int $course_offering_id
 * @property Carbon $held_on
 * @property string $starts_at
 * @property string $ends_at
 * @property int|null $invigilator_id
 * @property-read ExamPeriod $period
 * @property-read CourseOffering $courseOffering
 * @property-read Teacher|null $invigilator
 */
#[Fillable(['exam_period_id', 'course_offering_id', 'held_on', 'starts_at', 'ends_at', 'invigilator_id'])]
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
}
