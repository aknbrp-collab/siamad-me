<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's class in one semester. The class name is snapshotted so a later
 * rename never changes history.
 *
 * @property int $id
 * @property int $semester_id
 * @property int $class_group_id
 * @property int $student_id
 * @property string $class_name
 * @property-read Semester $semester
 * @property-read ClassGroup $classGroup
 * @property-read Student $student
 */
#[Fillable(['semester_id', 'class_group_id', 'student_id', 'class_name'])]
class ClassEnrollment extends Model
{
    use Auditable;

    /**
     * @return BelongsTo<Semester, $this>
     */
    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    /**
     * @return BelongsTo<ClassGroup, $this>
     */
    public function classGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
