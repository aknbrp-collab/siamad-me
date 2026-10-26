<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\HandoverStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A batch of cash payments that Admin Payment hands to the Admin (setoran).
 *
 * @property int $id
 * @property string $number
 * @property int $handed_over_by
 * @property int|null $received_by
 * @property int $total_amount
 * @property HandoverStatus $status
 * @property string|null $note
 * @property Carbon $submitted_at
 * @property Carbon|null $reviewed_at
 * @property-read User $handedOverBy
 * @property-read User|null $receivedBy
 * @property-read Collection<int, Payment> $payments
 */
#[Fillable(['number', 'handed_over_by', 'received_by', 'total_amount', 'status', 'note', 'submitted_at', 'reviewed_at'])]
class PaymentHandover extends Model
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
            'total_amount' => 'integer',
            'status' => HandoverStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function handedOverBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handed_over_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }
}
