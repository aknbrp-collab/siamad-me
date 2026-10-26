<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\ActivityCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A pesantren activity of a mahasantri (halaqah, tahfidz setoran…), kept for
 * monitoring. Never part of the KHS or transcript.
 *
 * @property int $id
 * @property int $student_id
 * @property ActivityCategory $category
 * @property Carbon $happened_on
 * @property string $title
 * @property string|null $notes
 * @property int $recorded_by
 * @property-read Student $student
 * @property-read User $recorder
 */
#[Fillable(['student_id', 'category', 'happened_on', 'title', 'notes', 'recorded_by'])]
class ActivityLog extends Model
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
            'category' => ActivityCategory::class,
            'happened_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
