<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\ExamType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * UTS or UAS of a semester. Its rooms and seats belong to the period: each
 * mahasantri keeps one room and seat number for every exam (v3 D7). While
 * unpublished the schedule and seats can change; once published they are
 * fixed and mahasantri can print their card.
 *
 * @property int $id
 * @property int $semester_id
 * @property ExamType $type
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property Carbon|null $published_at
 * @property Carbon|null $seats_assigned_at
 * @property-read Semester $semester
 * @property-read Collection<int, Room> $rooms
 * @property-read Collection<int, ExamSeat> $seats
 */
#[Fillable(['semester_id', 'type', 'starts_on', 'ends_on', 'published_at', 'seats_assigned_at'])]
class ExamPeriod extends Model
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
            'type' => ExamType::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'published_at' => 'datetime',
            'seats_assigned_at' => 'datetime',
        ];
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function label(): string
    {
        return $this->type->short().' · '.$this->loadMissing('semester')->semester->label();
    }

    /**
     * Schedule and seats change only before publishing, in an open semester.
     */
    public function assertEditable(): void
    {
        $this->loadMissing('semester')->semester->assertOpen();

        if ($this->isPublished()) {
            throw ValidationException::withMessages(['exam' => __('This exam period is published; its schedule and seats are fixed. Unpublish it first.')]);
        }
    }

    /**
     * @return BelongsTo<Semester, $this>
     */
    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    /**
     * @return HasMany<ExamSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(ExamSession::class);
    }

    /**
     * Rooms the period's mahasantri are seated in.
     *
     * @return BelongsToMany<Room, $this>
     */
    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class, 'exam_period_room');
    }

    /**
     * @return HasMany<ExamSeat, $this>
     */
    public function seats(): HasMany
    {
        return $this->hasMany(ExamSeat::class);
    }

    /**
     * @return HasMany<ExamDispensation, $this>
     */
    public function dispensations(): HasMany
    {
        return $this->hasMany(ExamDispensation::class);
    }
}
