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
 * A class (kelas). Since v4 (D20) a class is a cohort: it starts at
 * $start_level in its entry semester and moves up one level every semester,
 * named "Semester {roman} {label}". $name and $level hold the values for the
 * current semester and are refreshed when a semester is activated; use
 * levelIn() / nameIn() for any other semester. Classes made before v4 keep a
 * fixed name and level and can be used up to $last_semester_id.
 *
 * @property int $id
 * @property int $study_program_id
 * @property string $name
 * @property string|null $label Cohort classes: the name without the level, e.g. "Putra A"
 * @property int $level The semester of study now: 1 = Semester I, 2 = Semester II …
 * @property int|null $entry_semester_id Cohort classes: the semester they start in
 * @property int|null $start_level Cohort classes: their level in the entry semester
 * @property int|null $last_semester_id Classes made before v4: the last semester they can be used
 * @property Gender|null $gender Putra or Putri; null only for classes created before v1
 * @property-read StudyProgram $studyProgram
 * @property-read Semester|null $entrySemester
 * @property-read Semester|null $lastSemester
 */
#[Fillable(['study_program_id', 'name', 'label', 'level', 'entry_semester_id', 'start_level', 'last_semester_id', 'gender'])]
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
    public function description(?Semester $semester = null): string
    {
        $level = $semester ? self::levelName($this->levelIn($semester)) : $this->levelLabel();

        return $level.($this->gender ? ' · '.$this->gender->classLabel() : '');
    }

    public function isCohort(): bool
    {
        return $this->entry_semester_id !== null;
    }

    /**
     * The semester of study of this class in $semester.
     */
    public function levelIn(Semester $semester): int
    {
        if (! $this->isCohort() || $this->loadMissing('entrySemester.academicYear')->entrySemester === null) {
            return $this->level;
        }

        return (int) $this->start_level + $semester->ordinal() - $this->entrySemester->ordinal();
    }

    /**
     * The class name in $semester, e.g. "Semester III Putra A".
     */
    public function nameIn(Semester $semester): string
    {
        return $this->isCohort() ? self::cohortName($this->levelIn($semester), (string) $this->label) : $this->name;
    }

    public static function cohortName(int $level, string $label): string
    {
        return trim(self::levelName($level).' '.$label);
    }

    /**
     * Can students be placed and subjects offered in this class in $semester?
     * A cohort from its entry semester until it passes the last level; an
     * older class up to its last semester.
     */
    public function usableIn(Semester $semester): bool
    {
        if ($this->trashed()) {
            return false;
        }

        if (! $this->isCohort()) {
            return $this->loadMissing('lastSemester.academicYear')->lastSemester === null || $semester->ordinal() <= $this->lastSemester->ordinal();
        }

        $level = $this->levelIn($semester);

        return $level >= 1 && $level <= self::MAX_LEVEL;
    }

    /**
     * A copy showing the name and level of $semester, for pages about that
     * semester. Never save it.
     */
    public function presentIn(Semester $semester): static
    {
        $copy = clone $this;
        $copy->setAttribute('level', $this->levelIn($semester));
        $copy->setAttribute('name', $this->nameIn($semester));

        return $copy;
    }

    /**
     * Set $level and $name to their values in $semester (the current one).
     * A cohort that has not started yet shows its first level.
     */
    public function refreshFor(Semester $semester): void
    {
        if (! $this->isCohort() || $this->loadMissing('entrySemester.academicYear')->entrySemester === null) {
            return;
        }

        $at = $semester->ordinal() < $this->entrySemester->ordinal() ? $this->entrySemester : $semester;
        $level = max(1, min($this->levelIn($at), self::MAX_LEVEL));

        $this->forceFill(['level' => $level, 'name' => self::cohortName($level, (string) $this->label)])->save();
    }

    /**
     * @return BelongsTo<Semester, $this>
     */
    public function entrySemester(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'entry_semester_id');
    }

    /**
     * @return BelongsTo<Semester, $this>
     */
    public function lastSemester(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'last_semester_id');
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
