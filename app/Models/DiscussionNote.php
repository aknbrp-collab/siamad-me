<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A pinned note of a room: written by any member who may post, edited by its
 * author or a moderator. Removing hides it.
 *
 * @property int $id
 * @property int $discussion_id
 * @property int $user_id
 * @property string $title
 * @property string $body
 * @property int|null $updated_by
 * @property Carbon|null $hidden_at
 * @property Carbon $updated_at
 * @property-read User $user
 * @property-read User|null $editor
 */
#[Fillable(['discussion_id', 'user_id', 'title', 'body', 'updated_by', 'hidden_at'])]
class DiscussionNote extends Model
{
    use Auditable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['hidden_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
