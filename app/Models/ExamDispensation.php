<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Permission to sit an exam period without meeting the card requirements,
 * on the strength of a letter from the rector.
 *
 * @property int $id
 * @property int $exam_period_id
 * @property int $student_id
 * @property string $letter_number
 * @property Carbon $letter_date
 * @property string|null $note
 * @property int $granted_by
 * @property-read ExamPeriod $period
 * @property-read Student $student
 * @property-read User $grantedBy
 */
#[Fillable(['exam_period_id', 'student_id', 'letter_number', 'letter_date', 'note', 'granted_by'])]
class ExamDispensation extends Model
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
            'letter_date' => 'date',
        ];
    }

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
     * @return BelongsTo<User, $this>
     */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
