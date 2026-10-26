<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\SemesterStatus;
use App\Enums\SemesterTerm;
use Database\Factories\SemesterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * @property int $id
 * @property int $academic_year_id
 * @property SemesterTerm $term
 * @property SemesterStatus $status
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property string|null $status_note
 * @property-read AcademicYear $academicYear
 */
#[Fillable(['academic_year_id', 'term', 'status', 'starts_on', 'ends_on', 'status_note'])]
class Semester extends Model
{
    /** @use HasFactory<SemesterFactory> */
    use Auditable, HasFactory;

    /**
     * Matches the column default, so new instances have it before saving.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => SemesterStatus::Planned->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'term' => SemesterTerm::class,
            'status' => SemesterStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public static function active(): ?self
    {
        return self::query()->with('academicYear')->where('status', SemesterStatus::Active)->first();
    }

    /**
     * Sort key putting semesters in order: academic year, then odd before even.
     */
    public function chronology(): string
    {
        return $this->loadMissing('academicYear')->academicYear->name.'-'.($this->term === SemesterTerm::Odd ? 1 : 2);
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * @return HasMany<ClassEnrollment, $this>
     */
    public function classEnrollments(): HasMany
    {
        return $this->hasMany(ClassEnrollment::class);
    }

    /**
     * @return HasMany<CourseOffering, $this>
     */
    public function courseOfferings(): HasMany
    {
        return $this->hasMany(CourseOffering::class);
    }

    public function label(): string
    {
        return $this->loadMissing('academicYear')->academicYear->name.' · '.$this->term->label();
    }

    public function isLocked(): bool
    {
        return $this->status === SemesterStatus::Closed;
    }

    /**
     * Refuse any change to the semester's records once it is closed
     * (zero-overwriting rule).
     *
     * @throws ValidationException
     */
    public function assertOpen(): void
    {
        if ($this->isLocked()) {
            throw ValidationException::withMessages([
                'semester' => __('Semester :semester is closed. Its records are read-only.', ['semester' => $this->label()]),
            ]);
        }
    }
}
