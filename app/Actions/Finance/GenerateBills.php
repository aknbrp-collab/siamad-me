<?php

namespace App\Actions\Finance;

use App\Enums\BillStatus;
use App\Enums\FeeFrequency;
use App\Enums\StudentStatus;
use App\Models\Bill;
use App\Models\ClassEnrollment;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\ScholarshipAward;
use App\Models\Semester;
use App\Models\Setting;
use App\Support\NumberSequence;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds semester bills from the fee rates of the semester's academic year.
 */
class GenerateBills
{
    /**
     * What would be billed: one entry per enrolled, active mahasantri without
     * a live bill this semester.
     *
     * @return Collection<int, array{enrollment: ClassEnrollment, lines: list<array{fee_type_id: int|null, scholarship_award_id: int|null, description: string, amount: int}>, gross: int, discount: int, net: int}>
     */
    public function preview(Semester $semester): Collection
    {
        $rates = FeeRate::query()
            ->with('feeType')
            ->where('academic_year_id', $semester->academic_year_id)
            ->whereHas('feeType', fn ($query) => $query->where('is_active', true))
            ->get();

        $billed = Bill::query()
            ->where('semester_id', $semester->id)
            ->where('status', '!=', BillStatus::Void)
            ->pluck('student_id');

        $enrollments = ClassEnrollment::query()
            ->with(['student.user', 'classGroup'])
            ->where('semester_id', $semester->id)
            ->whereNotIn('student_id', $billed)
            ->whereHas('student', fn ($query) => $query->where('status', StudentStatus::Active))
            ->get()
            ->sortBy('student.nim')
            ->values();

        // Fee types each student was already billed for (for one-time fees).
        $onceBilled = DB::table('bill_lines')
            ->join('bills', 'bills.id', '=', 'bill_lines.bill_id')
            ->where('bills.status', '!=', BillStatus::Void->value)
            ->whereNotNull('bill_lines.fee_type_id')
            ->whereIn('bills.student_id', $enrollments->pluck('student_id'))
            ->get(['bills.student_id', 'bill_lines.fee_type_id'])
            ->groupBy('student_id')
            ->map(fn ($rows) => $rows->map(fn ($row) => (int) $row->fee_type_id)->all());

        $awards = ScholarshipAward::query()
            ->with('scholarship')
            ->where('semester_id', $semester->id)
            ->whereHas('scholarship', fn ($query) => $query->where('is_active', true))
            ->get()
            ->groupBy('student_id');

        return $enrollments->map(function (ClassEnrollment $enrollment) use ($rates, $onceBilled, $awards) {
            $lines = [];

            foreach ($rates->groupBy('fee_type_id') as $feeTypeRates) {
                $rate = $this->bestRate($feeTypeRates, $enrollment);

                if ($rate === null) {
                    continue;
                }

                $feeType = $rate->feeType;

                if ($feeType->frequency === FeeFrequency::Once && in_array($feeType->id, $onceBilled->get($enrollment->student_id, []), true)) {
                    continue;
                }

                $months = $feeType->frequency === FeeFrequency::Monthly ? FeeFrequency::MONTHS_PER_SEMESTER : 1;
                $lines[] = $this->line($feeType->id, null, $this->describe($feeType), $rate->amount * $months);
            }

            $gross = 0;
            foreach ($lines as $line) {
                $gross += $line['amount'];
            }

            $discount = 0;

            foreach ($awards->get($enrollment->student_id, collect()) as $award) {
                $amount = $this->cap($award->scholarship->discountFor($gross), $gross - $discount);

                if ($amount > 0) {
                    $lines[] = $this->line(null, $award->id, $this->text('Scholarship: :name', ['name' => $award->scholarship->name]), -$amount);
                    $discount += $amount;
                }
            }

            return [
                'enrollment' => $enrollment,
                'lines' => $lines,
                'gross' => $gross,
                'discount' => $discount,
                'net' => $gross - $discount,
            ];
        })->values();
    }

    /**
     * Create the bills from the preview. Students with nothing to bill are skipped.
     *
     * @return int Number of bills created.
     */
    public function handle(Semester $semester, ?string $dueOn = null): int
    {
        $semester->assertOpen();

        return DB::transaction(function () use ($semester, $dueOn) {
            $created = 0;
            $maxInstallments = (int) Setting::get('payment.max_installments', 3);

            foreach ($this->preview($semester) as $entry) {
                if ($entry['gross'] === 0) {
                    continue;
                }

                $bill = Bill::create([
                    'student_id' => $entry['enrollment']->student_id,
                    'semester_id' => $semester->id,
                    'number' => NumberSequence::next('INV'),
                    'due_on' => $dueOn,
                    'gross_amount' => 0,
                    'net_amount' => 0,
                    'max_installments' => $maxInstallments,
                    'status' => BillStatus::Unpaid,
                ]);

                $bill->lines()->createMany($entry['lines']);
                $bill->recalculate();
                $created++;
            }

            return $created;
        });
    }

    /**
     * @param  Collection<int, FeeRate>  $rates  Rates of one fee type.
     */
    private function bestRate(Collection $rates, ClassEnrollment $enrollment): ?FeeRate
    {
        return $rates
            ->filter(fn (FeeRate $rate) => ($rate->class_group_id === null || $rate->class_group_id === $enrollment->class_group_id)
                && ($rate->entry_year === null || $rate->entry_year === $enrollment->student->entry_year))
            ->sortByDesc(fn (FeeRate $rate) => $rate->specificity())
            ->first();
    }

    private function describe(FeeType $feeType): string
    {
        return $feeType->frequency === FeeFrequency::Monthly
            ? $this->text(':fee (:months months)', ['fee' => $feeType->name, 'months' => FeeFrequency::MONTHS_PER_SEMESTER])
            : $feeType->name;
    }

    /**
     * Discounts never exceed what is left of the bill.
     */
    private function cap(int $discount, int $remaining): int
    {
        return min($discount, $remaining);
    }

    /**
     * @return array{fee_type_id: int|null, scholarship_award_id: int|null, description: string, amount: int}
     */
    private function line(?int $feeTypeId, ?int $awardId, string $description, int $amount): array
    {
        return ['fee_type_id' => $feeTypeId, 'scholarship_award_id' => $awardId, 'description' => $description, 'amount' => $amount];
    }

    /**
     * A translated line; falls back to the key if a translation file maps it to an array.
     *
     * @param  array<string, string|int>  $replace
     */
    private function text(string $key, array $replace): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }
}
