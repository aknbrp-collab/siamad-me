<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Support\Forum\ForumContext;
use App\Support\Forum\OfferingForum;
use App\Support\Forum\SupervisionForum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $course_offering_id Set for a class forum
 * @property int|null $supervisor_id Set for a supervision group forum (the supervising muhadhir)
 * @property int $user_id
 * @property string $title
 * @property string $body
 * @property bool $is_pinned
 * @property bool $is_locked
 * @property Carbon $last_posted_at
 * @property Carbon $created_at
 * @property-read User $user
 * @property-read CourseOffering|null $courseOffering
 * @property-read Teacher|null $supervisor
 * @property-read Collection<int, ForumPost> $posts
 */
#[Fillable(['course_offering_id', 'supervisor_id', 'user_id', 'title', 'body', 'is_pinned', 'is_locked', 'last_posted_at'])]
class ForumThread extends Model
{
    use Auditable, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_pinned' => false,
        'is_locked' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
            'is_locked' => 'boolean',
            'last_posted_at' => 'datetime',
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
     * @return BelongsTo<CourseOffering, $this>
     */
    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class);
    }

    /**
     * The forum this thread belongs to: its class, or its supervision group.
     */
    public function context(): ForumContext
    {
        return $this->course_offering_id !== null
            ? new OfferingForum($this->loadMissing('courseOffering')->courseOffering)
            : new SupervisionForum($this->loadMissing('supervisor')->supervisor);
    }

    /**
     * The supervising muhadhir, for a supervision group thread.
     *
     * @return BelongsTo<Teacher, $this>
     */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'supervisor_id');
    }

    /**
     * @return HasMany<ForumPost, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(ForumPost::class)->orderBy('id');
    }
}
