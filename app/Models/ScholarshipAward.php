<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $scholarship_id
 * @property int $student_id
 * @property int $semester_id
 * @property int|null $approved_by
 * @property string|null $note
 * @property-read Scholarship $scholarship
 * @property-read Student $student
 * @property-read Semester $semester
 * @property-read BillLine|null $billLine
 */
#[Fillable(['scholarship_id', 'student_id', 'semester_id', 'approved_by', 'note'])]
class ScholarshipAward extends Model
{
    use Auditable;

    /**
     * @return BelongsTo<Scholarship, $this>
     */
    public function scholarship(): BelongsTo
    {
        return $this->belongsTo(Scholarship::class);
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<Semester, $this>
     */
    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    /**
     * The discount line on the semester's bill, once applied.
     *
     * @return HasOne<BillLine, $this>
     */
    public function billLine(): HasOne
    {
        return $this->hasOne(BillLine::class);
    }
}
