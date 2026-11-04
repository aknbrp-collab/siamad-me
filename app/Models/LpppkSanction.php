<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An LPPPK-wide mute or restriction of one person (v5 D28).
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon|null $muted_at
 * @property Carbon|null $muted_until Null while muted means until lifted
 * @property Carbon|null $restricted_at No access to LPPPK at all
 * @property string|null $reason
 * @property int $decided_by
 * @property Carbon $updated_at
 * @property-read User $user
 * @property-read User $decider
 */
#[Fillable(['user_id', 'muted_at', 'muted_until', 'restricted_at', 'reason', 'decided_by'])]
class LpppkSanction extends Model
{
    use Auditable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'muted_at' => 'datetime',
            'muted_until' => 'datetime',
            'restricted_at' => 'datetime',
        ];
    }

    public function isMuted(): bool
    {
        return $this->muted_at !== null && ($this->muted_until === null || $this->muted_until->isFuture());
    }

    public function isRestricted(): bool
    {
        return $this->restricted_at !== null;
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
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
