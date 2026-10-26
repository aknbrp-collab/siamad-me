<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\BillStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A mahasantri's bill for one semester. Amounts are snapshotted in the lines;
 * paid_amount and status are derived from verified payments only.
 *
 * @property int $id
 * @property int $student_id
 * @property int $semester_id
 * @property string $number
 * @property Carbon|null $due_on
 * @property int $gross_amount
 * @property int $discount_amount
 * @property int $net_amount
 * @property int $paid_amount
 * @property int $max_installments
 * @property BillStatus $status
 * @property string|null $status_note
 * @property-read Student $student
 * @property-read Semester $semester
 * @property-read Collection<int, BillLine> $lines
 * @property-read Collection<int, Payment> $payments
 */
#[Fillable([
    'student_id', 'semester_id', 'number', 'due_on', 'gross_amount', 'discount_amount', 'net_amount',
    'paid_amount', 'max_installments', 'status', 'status_note',
])]
class Bill extends Model
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
            'due_on' => 'date',
            'gross_amount' => 'integer',
            'discount_amount' => 'integer',
            'net_amount' => 'integer',
            'paid_amount' => 'integer',
            'max_installments' => 'integer',
            'status' => BillStatus::class,
        ];
    }

    /**
     * What is still owed after verified payments.
     */
    public function outstanding(): int
    {
        return max(0, $this->net_amount - $this->paid_amount);
    }

    /**
     * Recompute totals from the lines and paid/status from verified payments.
     * A void bill keeps its status.
     */
    public function recalculate(): void
    {
        $lines = $this->lines()->get();

        $this->gross_amount = (int) $lines->where('amount', '>', 0)->sum('amount');
        $this->discount_amount = (int) -$lines->where('amount', '<', 0)->sum('amount');
        $this->net_amount = max(0, $this->gross_amount - $this->discount_amount);
        $this->paid_amount = (int) $this->payments()->where('status', PaymentStatus::Verified)->sum('amount');

        if ($this->status !== BillStatus::Void) {
            $this->status = match (true) {
                $this->paid_amount >= $this->net_amount => BillStatus::Paid,
                $this->paid_amount > 0 => BillStatus::Partial,
                default => BillStatus::Unpaid,
            };
        }

        $this->save();
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<Semester, $this>
     */
    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    /**
     * @return HasMany<BillLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BillLine::class)->orderBy('id');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }
}
