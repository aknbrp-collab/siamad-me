<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A subject taught to one class by one teacher in one semester: the hub for
 * attendance, grades and the LMS. Subject code, name and credits are
 * snapshotted at creation.
 *
 * @property int $id
 * @property int $semester_id
 * @property int $subject_id
 * @property int $class_group_id
 * @property string $class_name The class name in this offering's semester (snapshot)
 * @property int $teacher_id
 * @property string $subject_code
 * @property string $subject_name
 * @property int $credits
 * @property int|null $day_of_week
 * @property string|null $starts_at
 * @property string|null $ends_at
 * @property string|null $room
 * @property Carbon|null $grades_finalized_at
 * @property int|null $grades_finalized_by
 * @property string|null $grades_note
 * @property int|null $joined_to_id The lead offering when taught together with other classes (v3 Step 7)
 * @property-read Semester $semester
 * @property-read Subject $subject
 * @property-read ClassGroup $classGroup
 * @property-read Teacher $teacher
 */
#[Fillable([
    'semester_id', 'subject_id', 'class_group_id', 'class_name', 'teacher_id', 'subject_code', 'subject_name', 'credits',
    'day_of_week', 'starts_at', 'ends_at', 'room', 'grades_finalized_at', 'grades_finalized_by', 'grades_note', 'joined_to_id',
])]
class CourseOffering extends Model
{
    use Auditable;

    protected static function booted(): void
    {
        // The class as it is named in this semester (classes move up, v4 D20).
        static::saving(function (CourseOffering $offering) {
            if ($offering->isDirty('class_group_id') || blank($offering->class_name)) {
                $class = ClassGroup::withTrashed()->with('entrySemester.academicYear')->findOrFail($offering->class_group_id);
                $offering->class_name = $class->nameIn(Semester::query()->with('academicYear')->findOrFail($offering->semester_id));
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credits' => 'integer',
            'day_of_week' => 'integer',
            'grades_finalized_at' => 'datetime',
        ];
    }

    public function isFinalized(): bool
    {
        return $this->grades_finalized_at !== null;
    }

    /**
     * Day names indexed by ISO day number (1 = Monday).
     *
     * @return array<int, string>
     */
    public static function days(): array
    {
        return [
            1 => __('Monday'), 2 => __('Tuesday'), 3 => __('Wednesday'), 4 => __('Thursday'),
            5 => __('Friday'), 6 => __('Saturday'), 7 => __('Sunday'),
        ];
    }

    public function scheduleLabel(): string
    {
        if ($this->day_of_week === null) {
            return '—';
        }

        $label = self::days()[$this->day_of_week];

        if ($this->starts_at) {
            $label .= ' '.substr($this->starts_at, 0, 5);
            $label .= $this->ends_at ? '–'.substr($this->ends_at, 0, 5) : '';
        }

        return $this->room ? "{$label} · {$this->room}" : $label;
    }

    /**
     * @return BelongsTo<Semester, $this>
     */
    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class)->withTrashed();
    }

    /**
     * @return BelongsTo<ClassGroup, $this>
     */
    public function classGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * @return HasMany<CourseEnrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(CourseEnrollment::class);
    }

    /**
     * @return HasMany<GradeComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(GradeComponent::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<ClassSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(ClassSession::class)->orderBy('meeting_no');
    }

    /**
     * The offering that holds the teaching documents: itself, or the lead of
     * the classes it is taught together with.
     */
    public function teachingLeadId(): int
    {
        return $this->joined_to_id ?? $this->id;
    }

    /**
     * @return HasMany<TeachingDocument, $this>
     */
    public function teachingDocuments(): HasMany
    {
        return $this->hasMany(TeachingDocument::class);
    }

    /**
     * @return HasMany<Material, $this>
     */
    public function materials(): HasMany
    {
        return $this->hasMany(Material::class)->latest('id');
    }

    /**
     * @return HasMany<Assignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class)->orderBy('due_at');
    }

    /**
     * @return HasMany<Quiz, $this>
     */
    public function quizzes(): HasMany
    {
        return $this->hasMany(Quiz::class)->orderBy('opens_at');
    }

    /**
     * @return HasMany<ForumThread, $this>
     */
    public function forumThreads(): HasMany
    {
        return $this->hasMany(ForumThread::class);
    }
}
