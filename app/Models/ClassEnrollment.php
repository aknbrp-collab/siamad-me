<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's class in one semester. The class name and level in that
 * semester are snapshotted whenever the class is set, so a later rename or a
 * class moving up never changes history.
 *
 * @property int $id
 * @property int $semester_id
 * @property int $class_group_id
 * @property int $student_id
 * @property string $class_name
 * @property int|null $level
 * @property-read Semester $semester
 * @property-read ClassGroup $classGroup
 * @property-read Student $student
 */
#[Fillable(['semester_id', 'class_group_id', 'student_id', 'class_name', 'level'])]
class ClassEnrollment extends Model
{
    use Auditable;

    protected static function booted(): void
    {
        static::saving(function (ClassEnrollment $enrollment) {
            if ($enrollment->isDirty('class_group_id') || $enrollment->level === null) {
                $class = ClassGroup::withTrashed()->with('entrySemester.academicYear')->findOrFail($enrollment->class_group_id);
                $semester = Semester::query()->with('academicYear')->findOrFail($enrollment->semester_id);
                $enrollment->class_name = $class->nameIn($semester);
                $enrollment->level = $class->levelIn($semester);
            }
        });
    }

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
