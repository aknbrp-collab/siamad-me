<?php

namespace App\Actions\Finance;

use App\Enums\BillStatus;
use App\Enums\PaymentStatus;
use App\Enums\StudentStatus;
use App\Models\Bill;
use App\Models\Scholarship;
use App\Models\ScholarshipAward;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageScholarshipAwards
{
    /**
     * Award a scholarship to a mahasantri for one semester. If the semester's
     * bill already exists, the discount is added to it straight away.
     */
    public function award(Scholarship $scholarship, Student $student, Semester $semester, User $approver, ?string $note = null): ScholarshipAward
    {
        $semester->assertOpen();

        if (! $scholarship->is_active) {
            $this->fail(__('This scholarship is no longer active.'));
        }

        if ($semester->academic_year_id !== $scholarship->academic_year_id) {
            $this->fail(__('The semester is not in this scholarship\'s academic year.'));
        }

        if ($student->status !== StudentStatus::Active) {
            $this->fail(__('Only active mahasantri can receive a scholarship.'));
        }

        return DB::transaction(function () use ($scholarship, $student, $semester, $approver, $note) {
            if ($scholarship->awards()->where('student_id', $student->id)->where('semester_id', $semester->id)->exists()) {
                $this->fail(__('This mahasantri already has this scholarship for the semester.'));
            }

            $recipients = $scholarship->awards()->distinct()->pluck('student_id');

            if ($scholarship->quota !== null && ! $recipients->contains($student->id) && $recipients->count() >= $scholarship->quota) {
                $this->fail(__('The quota of :quota mahasantri is full.', ['quota' => $scholarship->quota]));
            }

            $award = $scholarship->awards()->create([
                'student_id' => $student->id,
                'semester_id' => $semester->id,
                'approved_by' => $approver->id,
                'note' => $note,
            ]);

            $bill = $this->liveBill($student, $semester);

            if ($bill !== null) {
                $amount = min($scholarship->discountFor($bill->gross_amount), $bill->net_amount);

                if ($amount > 0) {
                    $this->ensureCovers($bill, $bill->net_amount - $amount);

                    $bill->lines()->create([
                        'scholarship_award_id' => $award->id,
                        'description' => __('Scholarship: :name', ['name' => $scholarship->name]),
                        'amount' => -$amount,
                    ]);
                    $bill->recalculate();
                }
            }

            return $award;
        });
    }

    /**
     * Withdraw an award and remove its discount from the bill.
     */
    public function revoke(ScholarshipAward $award): void
    {
        $award->loadMissing(['semester', 'billLine.bill']);
        $award->semester->assertOpen();

        DB::transaction(function () use ($award) {
            $line = $award->billLine;

            if ($line !== null) {
                $bill = $line->bill;
                $line->delete();
                $bill->recalculate();
            }

            $award->delete();
        });
    }

    private function liveBill(Student $student, Semester $semester): ?Bill
    {
        return Bill::query()
            ->where('student_id', $student->id)
            ->where('semester_id', $semester->id)
            ->where('status', '!=', BillStatus::Void)
            ->lockForUpdate()
            ->first();
    }

    /**
     * A discount may not push the bill below what is already paid or pending.
     */
    private function ensureCovers(Bill $bill, int $newNet): void
    {
        $reserved = (int) $bill->payments()->whereIn('status', PaymentStatus::counting())->sum('amount');

        if ($newNet < $reserved) {
            $this->fail(__('Payments of :amount are already recorded on this bill; the discount would exceed what is left.', [
                'amount' => Money::format($reserved),
            ]));
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['award' => $message]);
    }
}
