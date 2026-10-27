<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One period of maintenance mode (previous-year data entry). Not audited
 * itself: the session is the audit trail, and changes made during it point
 * back to it.
 *
 * @property int $id
 * @property int $started_by
 * @property string $reason
 * @property Carbon $started_at
 * @property int|null $ended_by
 * @property Carbon|null $ended_at
 * @property-read User $starter
 * @property-read User|null $ender
 */
#[Fillable(['started_by', 'reason', 'started_at', 'ended_by', 'ended_at'])]
class MaintenanceSession extends Model
{
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function ender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    /**
     * @return HasMany<AuditLog, $this>
     */
    public function changes(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
