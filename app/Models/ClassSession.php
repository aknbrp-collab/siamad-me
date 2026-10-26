<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\MeetingMode;
use App\Enums\MeetingProvider;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One meeting (pertemuan) of a course offering, in class or online.
 *
 * @property int $id
 * @property int $course_offering_id
 * @property int $meeting_no
 * @property Carbon $held_on
 * @property string|null $topic
 * @property MeetingMode $mode
 * @property MeetingProvider|null $meeting_provider
 * @property string|null $meeting_url
 * @property Carbon|null $attendance_taken_at
 * @property-read CourseOffering $courseOffering
 * @property-read Collection<int, Attendance> $attendances
 */
#[Fillable(['course_offering_id', 'meeting_no', 'held_on', 'topic', 'mode', 'meeting_provider', 'meeting_url', 'attendance_taken_at'])]
class ClassSession extends Model
{
    use Auditable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'mode' => 'offline',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meeting_no' => 'integer',
            'held_on' => 'date',
            'mode' => MeetingMode::class,
            'meeting_provider' => MeetingProvider::class,
            'attendance_taken_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CourseOffering, $this>
     */
    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class);
    }

    /**
     * @return HasMany<Attendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }
}
