<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\Gender;
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
 * @property int $level The semester of study: 1 = Semester I, 2 = Semester II …
 * @property Gender|null $gender Putra or Putri; null only for classes created before v1
 * @property-read StudyProgram $studyProgram
 */
#[Fillable(['study_program_id', 'name', 'level', 'gender'])]
class ClassGroup extends Model
{
    /** @use HasFactory<ClassGroupFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /** Highest semester of study a class can have. */
    public const MAX_LEVEL = 14;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
        ];
    }

    /**
     * "Semester III" for level 3.
     */
    public static function levelName(int $level): string
    {
        $numerals = [1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD', 100 => 'C', 90 => 'XC', 50 => 'L', 40 => 'XL', 10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I'];
        $roman = '';

        foreach ($numerals as $value => $symbol) {
            while ($level >= $value) {
                $roman .= $symbol;
                $level -= $value;
            }
        }

        return __('Semester :level', ['level' => $roman]);
    }

    public function levelLabel(): string
    {
        return self::levelName($this->level);
    }

    /**
     * "Semester III · Putra", or just the level while the gender is not set.
     */
    public function description(): string
    {
        return $this->levelLabel().($this->gender ? ' · '.$this->gender->classLabel() : '');
    }

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
