<?php

namespace App\Actions\Finance;

use App\Enums\BillStatus;
use App\Enums\FeeFrequency;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\ClassEnrollment;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Semester;
use App\Models\User;
use App\Support\Money;
use App\Support\Notify;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Add a fee to bills that already exist (v4 D23). Bills are snapshots: a fee
 * (or rate) added after "Generate bills" never reaches them by itself. The
 * fee is added as a new line at the rate that bill generation would use, and
 * percentage scholarships grow with it. Voided bills and bills that already
 * have the fee are skipped; paid bills become partly paid again.
 */
class AddFeeToBills
{
    public function __construct(private readonly GenerateBills $generate) {}

    /**
     * What would be added, per bill, and how many bills are skipped and why.
     *
     * @return array{entries: Collection<int, array{bill: Bill, class: string, amount: int, discount: int, lines: list<array{fee_type_id: int|null, scholarship_award_id: int|null, description: string, amount: int}>}>, skipped: array{has_fee: int, no_rate: int, once: int}}
     */
    public function preview(FeeType $feeType, Semester $semester): array
    {
        $rates = FeeRate::query()->with('feeType')
            ->where('academic_year_id', $semester->academic_year_id)
            ->where('fee_type_id', $feeType->id)
            ->get();

        $bills = Bill::query()
            ->with(['student.user', 'lines.scholarshipAward.scholarship'])
            ->where('semester_id', $semester->id)
            ->where('status', '!=', BillStatus::Void)
            ->get()
            ->sortBy('number')
            ->values();

        $enrollments = ClassEnrollment::query()->with('student')
            ->where('semester_id', $semester->id)
            ->whereIn('student_id', $bills->pluck('student_id'))
            ->get()
            ->keyBy('student_id');

        // A one-time fee already billed in any semester is not billed again.
        $onceBilled = $feeType->frequency === FeeFrequency::Once
            ? BillLine::query()
                ->join('bills', 'bills.id', '=', 'bill_lines.bill_id')
                ->where('bill_lines.fee_type_id', $feeType->id)
                ->where('bills.status', '!=', BillStatus::Void->value)
                ->pluck('bills.student_id')->map(fn ($id) => (int) $id)->unique()->values()->all()
            : [];

        $skipped = ['has_fee' => 0, 'no_rate' => 0, 'once' => 0];
        $entries = collect();

        foreach ($bills as $bill) {
            if ($bill->lines->contains('fee_type_id', $feeType->id)) {
                $skipped['has_fee']++;

                continue;
            }

            if (in_array($bill->student_id, $onceBilled, true)) {
                $skipped['once']++;

                continue;
            }

            $enrollment = $enrollments->get($bill->student_id);
            $rate = $enrollment ? $this->generate->bestRate($rates, $enrollment) : null;

            if ($rate === null) {
                $skipped['no_rate']++;

                continue;
            }

            $months = $feeType->frequency === FeeFrequency::Monthly ? FeeFrequency::MONTHS_PER_SEMESTER : 1;
            $amount = $rate->amount * $months;
            $lines = [['fee_type_id' => $feeType->id, 'scholarship_award_id' => null, 'description' => $this->generate->describe($feeType), 'amount' => $amount]];

            $discount = 0;
            $gross = (int) $bill->lines->where('amount', '>', 0)->sum('amount') + $amount;
            $given = (int) -$bill->lines->where('amount', '<', 0)->sum('amount');

            // Each scholarship covers the larger bill as it would have when generated.
            foreach ($bill->lines->whereNotNull('scholarship_award_id')->groupBy('scholarship_award_id') as $awardLines) {
                $award = $awardLines->first()->scholarshipAward;
                $extra = min(
                    $award->scholarship->discountFor($gross) - (int) -$awardLines->sum('amount'),
                    $gross - $given - $discount,
                );

                if ($extra > 0) {
                    $lines[] = [
                        'fee_type_id' => null,
                        'scholarship_award_id' => $award->id,
                        'description' => __('Scholarship: :name (:fee)', ['name' => $award->scholarship->name, 'fee' => $feeType->name]),
                        'amount' => -$extra,
                    ];
                    $discount += $extra;
                }
            }

            $entries->push([
                'bill' => $bill,
                'class' => $enrollment->class_name,
                'amount' => $amount,
                'discount' => $discount,
                'lines' => $lines,
            ]);
        }

        return ['entries' => $entries, 'skipped' => $skipped];
    }

    /**
     * Add the fee to every bill in the preview. Returns how many bills changed.
     */
    public function handle(FeeType $feeType, Semester $semester, User $by): int
    {
        $semester->assertOpen();

        if (! $feeType->is_active) {
            throw ValidationException::withMessages(['addFee' => __('This fee is inactive; activate it first.')]);
        }

        $entries = DB::transaction(function () use ($feeType, $semester) {
            $entries = $this->preview($feeType, $semester)['entries'];

            foreach ($entries as $entry) {
                $bill = Bill::query()->whereKey($entry['bill']->id)->lockForUpdate()->firstOrFail();

                // Checked again under the lock, so a second click adds nothing twice.
                if ($bill->lines()->where('fee_type_id', $feeType->id)->exists()) {
                    continue;
                }

                $bill->lines()->createMany($entry['lines']);
                $bill->recalculate();
            }

            return $entries;
        });

        foreach ($entries as $entry) {
            $student = $entry['bill']->student->loadMissing('guardians.user');

            Notify::send(
                [$student->user, ...$student->guardians->pluck('user')],
                'payment',
                __(':fee added to bill :number', ['fee' => $feeType->name, 'number' => $entry['bill']->number]),
                __(':amount added. The bill now totals :total.', [
                    'amount' => Money::format($entry['amount'] - $entry['discount']),
                    'total' => Money::format($entry['bill']->refresh()->net_amount),
                ]),
                fn (User $user) => route($user->is($student->user) ? 'mahasantri.bills' : 'wali.bills'),
                'document-currency-dollar',
                $by,
            );
        }

        return $entries->count();
    }
}
