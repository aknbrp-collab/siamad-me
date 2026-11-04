<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person in a room, with their read marker and mute. LPPPK rows are made
 * on the first visit (everyone may join); bimbingan rows by Admin.
 *
 * @property int $id
 * @property int $discussion_id
 * @property int $user_id
 * @property string $role lead | member
 * @property Carbon|null $muted_at
 * @property Carbon|null $muted_until Null while muted means until lifted
 * @property int $last_read_message_id
 * @property-read Discussion $discussion
 * @property-read User $user
 */
#[Fillable(['discussion_id', 'user_id', 'role', 'muted_at', 'muted_until', 'last_read_message_id'])]
class DiscussionMember extends Model
{
    use Auditable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'member',
        'last_read_message_id' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'muted_at' => 'datetime',
            'muted_until' => 'datetime',
            'last_read_message_id' => 'integer',
        ];
    }

    public function isMuted(): bool
    {
        return $this->muted_at !== null && ($this->muted_until === null || $this->muted_until->isFuture());
    }

    /**
     * @return BelongsTo<Discussion, $this>
     */
    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
