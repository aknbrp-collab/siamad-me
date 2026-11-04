<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A file shared in a room, attached to the message that brought it. Served
 * by PrivateFileController (kind "discussion-file") to the room's members.
 *
 * @property int $id
 * @property int $discussion_id
 * @property int $discussion_message_id
 * @property int $user_id
 * @property string $file_path
 * @property string $original_name
 * @property int $size
 * @property Carbon $created_at
 * @property-read Discussion $discussion
 * @property-read DiscussionMessage $message
 * @property-read User $user
 */
#[Fillable(['discussion_id', 'discussion_message_id', 'user_id', 'file_path', 'original_name', 'size'])]
class DiscussionFile extends Model
{
    use Auditable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /**
     * @return BelongsTo<Discussion, $this>
     */
    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class);
    }

    /**
     * @return BelongsTo<DiscussionMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(DiscussionMessage::class, 'discussion_message_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
