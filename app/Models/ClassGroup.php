<?php

namespace App\Models;

use App\Concerns\Auditable;
use Database\Factories\ClassGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $study_program_id
 * @property string $name
 * @property int $level
 * @property-read StudyProgram $studyProgram
 */
#[Fillable(['study_program_id', 'name', 'level'])]
class ClassGroup extends Model
{
    /** @use HasFactory<ClassGroupFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return BelongsTo<StudyProgram, $this>
     */
    public function studyProgram(): BelongsTo
    {
        return $this->belongsTo(StudyProgram::class)->withTrashed();
    }

    /**
     * @return HasMany<ClassEnrollment, $this>
     */
    public function classEnrollments(): HasMany
    {
        return $this->hasMany(ClassEnrollment::class);
    }
}
