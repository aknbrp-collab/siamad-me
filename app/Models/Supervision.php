<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A muhadhir supervising a mahasantri (pembimbing akademik) from started_on
 * until ended_on. An open row (ended_on null) is the current supervision.
 *
 * @property int $id
 * @property int $student_id
 * @property int $teacher_id
 * @property Carbon $started_on
 * @property Carbon|null $ended_on
 * @property int $assigned_by
 * @property-read Student $student
 * @property-read Teacher $teacher
 */
#[Fillable(['student_id', 'teacher_id', 'started_on', 'ended_on', 'assigned_by'])]
class Supervision extends Model
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
            'started_on' => 'date',
            'ended_on' => 'date',
        ];
    }

    /**
     * Supervisions still running (not ended).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function current(Builder $query): void
    {
        $query->whereNull('ended_on');
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}
