<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\SemesterStatus;
use App\Enums\SemesterTerm;
use App\Support\MaintenanceMode;
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
     * The semester as a running number (two per academic year), so the number
     * of semesters between two is a subtraction. Used for class levels.
     */
    public function ordinal(): int
    {
        return $this->loadMissing('academicYear')->academicYear->starts_on->year * 2 + ($this->term === SemesterTerm::Odd ? 0 : 1);
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
     * Closed, and not opened up by maintenance mode for the person acting now.
     * Staff pages use this; muhadhir and mahasantri always see a closed
     * semester as locked.
     */
    public function isReadOnly(): bool
    {
        return $this->isLocked() && ! MaintenanceMode::allowsEditing();
    }

    /**
     * Refuse any change to the semester's records once it is closed
     * (zero-overwriting rule), except in maintenance mode.
     *
     * @throws ValidationException
     */
    public function assertOpen(): void
    {
        if ($this->isReadOnly()) {
            throw ValidationException::withMessages([
                'semester' => __('Semester :semester is closed. Its records are read-only.', ['semester' => $this->label()]),
            ]);
        }
    }
}
