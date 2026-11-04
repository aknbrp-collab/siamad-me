<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\DiscussionKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A discussion room (v5 D26): a bimbingan group or an LPPPK channel. (Exam
 * rooms are App\Models\Room.)
 *
 * @property int $id
 * @property DiscussionKind $kind
 * @property string $name
 * @property string|null $description
 * @property int|null $lead_teacher_id
 * @property Carbon|null $locked_at Only moderators can post
 * @property Carbon|null $closed_at Read only (a finished bimbingan group)
 * @property int $created_by
 * @property-read Teacher|null $leadTeacher
 */
#[Fillable(['kind', 'name', 'description', 'lead_teacher_id', 'locked_at', 'closed_at', 'created_by'])]
class Discussion extends Model
{
    use Auditable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => DiscussionKind::class,
            'locked_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function leadTeacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'lead_teacher_id');
    }

    /**
     * @return HasMany<DiscussionMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(DiscussionMember::class);
    }

    /**
     * @return HasMany<DiscussionMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(DiscussionMessage::class);
    }

    /**
     * @return HasMany<DiscussionFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(DiscussionFile::class);
    }

    /**
     * @return HasMany<DiscussionNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(DiscussionNote::class);
    }
}
