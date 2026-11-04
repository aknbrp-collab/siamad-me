<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A chat message, optionally a reply and with files. A hidden message shows
 * "dihapus" to members; its text stays for the audit.
 *
 * @property int $id
 * @property int $discussion_id
 * @property int $user_id
 * @property int|null $reply_to_id
 * @property string|null $body
 * @property Carbon|null $hidden_at
 * @property int|null $hidden_by
 * @property Carbon $created_at
 * @property-read Discussion $discussion
 * @property-read User $user
 * @property-read DiscussionMessage|null $replyTo
 */
#[Fillable(['discussion_id', 'user_id', 'reply_to_id', 'body', 'hidden_at', 'hidden_by'])]
class DiscussionMessage extends Model
{
    use Auditable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['hidden_at' => 'datetime'];
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
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

    /**
     * @return BelongsTo<DiscussionMessage, $this>
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(DiscussionMessage::class, 'reply_to_id');
    }

    /**
     * @return HasMany<DiscussionFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(DiscussionFile::class);
    }
}
