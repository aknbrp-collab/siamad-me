<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\Gender;
use App\Enums\StudentStatus;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $nim
 * @property int $study_program_id
 * @property int $entry_year
 * @property Gender $gender
 * @property string|null $birth_place
 * @property Carbon|null $birth_date
 * @property string|null $address
 * @property string|null $phone
 * @property string|null $photo_path
 * @property string|null $father_name
 * @property bool|null $father_is_alive
 * @property string|null $mother_name
 * @property bool|null $mother_is_alive
 * @property string|null $guardian_name
 * @property StudentStatus $status
 * @property Carbon|null $graduated_at
 * @property-read User $user
 * @property-read StudyProgram $studyProgram
 * @property-read Collection<int, StudentDocument> $documents
 * @property-read Collection<int, Guardian> $guardians
 */
#[Fillable([
    'nim', 'study_program_id', 'entry_year', 'gender', 'birth_place', 'birth_date', 'address', 'phone', 'photo_path',
    'father_name', 'father_is_alive', 'mother_name', 'mother_is_alive', 'guardian_name', 'status', 'graduated_at',
])]
class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use Auditable, HasFactory;

    /**
     * Matches the column default, so new instances have it before saving.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => StudentStatus::Active->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_year' => 'integer',
            'gender' => Gender::class,
            'birth_date' => 'date',
            'father_is_alive' => 'boolean',
            'mother_is_alive' => 'boolean',
            'status' => StudentStatus::class,
            'graduated_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<StudyProgram, $this>
     */
    public function studyProgram(): BelongsTo
    {
        return $this->belongsTo(StudyProgram::class)->withTrashed();
    }

    /**
     * @return HasMany<StudentDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(StudentDocument::class);
    }

    /**
     * @return BelongsToMany<Guardian, $this>
     */
    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(Guardian::class)->withTimestamps();
    }

    /**
     * @return HasMany<ClassEnrollment, $this>
     */
    public function classEnrollments(): HasMany
    {
        return $this->hasMany(ClassEnrollment::class);
    }

    /**
     * @return HasMany<CourseEnrollment, $this>
     */
    public function courseEnrollments(): HasMany
    {
        return $this->hasMany(CourseEnrollment::class);
    }

    /**
     * @return HasMany<Bill, $this>
     */
    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    /**
     * @return HasMany<ScholarshipAward, $this>
     */
    public function scholarshipAwards(): HasMany
    {
        return $this->hasMany(ScholarshipAward::class);
    }

    /**
     * @return HasMany<ActivityLog, $this>
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class)->latest('happened_on')->latest('id');
    }

    /**
     * Every pembimbing akademik this mahasantri has had, newest first.
     *
     * @return HasMany<Supervision, $this>
     */
    public function supervisions(): HasMany
    {
        return $this->hasMany(Supervision::class)->latest('started_on')->latest('id');
    }

    /**
     * The current pembimbing akademik, if any.
     *
     * @return HasOne<Supervision, $this>
     */
    public function currentSupervision(): HasOne
    {
        return $this->hasOne(Supervision::class)->whereNull('ended_on');
    }
}
