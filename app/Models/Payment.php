<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\PaymentMethod;
use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money received for a bill. Recorded by Admin Payment (or confirmed by the
 * mahasantri / wali for a transfer) as unverified; only after it is verified
 * does it count, and a receipt number is issued.
 * Payments are never deleted: they are rejected or voided with a note.
 *
 * @property int $id
 * @property int $bill_id
 * @property int $amount
 * @property PaymentMethod $method
 * @property PaymentSource $source
 * @property Carbon $paid_on
 * @property string|null $reference
 * @property string|null $proof_path
 * @property PaymentStatus $status
 * @property int $recorded_by
 * @property int|null $verified_by
 * @property Carbon|null $verified_at
 * @property string|null $status_note
 * @property int|null $payment_handover_id
 * @property string|null $receipt_number
 * @property int|null $cash_account_id
 * @property-read CashAccount|null $cashAccount
 * @property-read Bill $bill
 * @property-read User $recorder
 * @property-read User|null $verifier
 * @property-read PaymentHandover|null $handover
 */
#[Fillable([
    'bill_id', 'amount', 'method', 'source', 'paid_on', 'reference', 'proof_path', 'status', 'recorded_by',
    'verified_by', 'verified_at', 'status_note', 'payment_handover_id', 'receipt_number', 'cash_account_id',
])]
class Payment extends Model
{
    use Auditable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'source' => 'staff',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'method' => PaymentMethod::class,
            'source' => PaymentSource::class,
            'paid_on' => 'date',
            'status' => PaymentStatus::class,
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Bill, $this>
     */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * The account the money went into (cash book, v3 D9).
     *
     * @return BelongsTo<CashAccount, $this>
     */
    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    /**
     * @return BelongsTo<PaymentHandover, $this>
     */
    public function handover(): BelongsTo
    {
        return $this->belongsTo(PaymentHandover::class, 'payment_handover_id');
    }
}
