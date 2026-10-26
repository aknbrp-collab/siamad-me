<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\Gender;
use App\Enums\TeacherStatus;
use Database\Factories\TeacherFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $nidm
 * @property string|null $nik
 * @property string|null $title
 * @property Gender $gender
 * @property string|null $birth_place
 * @property Carbon|null $birth_date
 * @property string|null $address
 * @property string|null $phone
 * @property string|null $photo_path
 * @property string|null $expertise
 * @property string|null $scopus_id
 * @property string|null $google_scholar_url
 * @property TeacherStatus $status
 * @property-read User $user
 * @property-read Collection<int, TeacherDocument> $documents
 */
#[Fillable([
    'nidm', 'nik', 'title', 'gender', 'birth_place', 'birth_date', 'address', 'phone', 'photo_path',
    'expertise', 'scopus_id', 'google_scholar_url', 'status',
])]
class Teacher extends Model
{
    /** @use HasFactory<TeacherFactory> */
    use Auditable, HasFactory;

    /**
     * Matches the column default, so new instances have it before saving.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => TeacherStatus::Active->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'birth_date' => 'date',
            'status' => TeacherStatus::class,
        ];
    }

    /**
     * Name with title (gelar), as printed on schedules and reports.
     */
    public function displayName(): string
    {
        $name = $this->loadMissing('user')->user->name;

        return $this->title ? "{$name}, {$this->title}" : $name;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<TeacherDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(TeacherDocument::class);
    }

    /**
     * @return HasMany<CourseOffering, $this>
     */
    public function courseOfferings(): HasMany
    {
        return $this->hasMany(CourseOffering::class);
    }

    /**
     * Mahasantri this muhadhir supervises now or did before (pembimbing akademik).
     *
     * @return HasMany<Supervision, $this>
     */
    public function supervisions(): HasMany
    {
        return $this->hasMany(Supervision::class);
    }
}
