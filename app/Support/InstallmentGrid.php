<?php

namespace App\Support;

use App\Enums\BillStatus;
use App\Enums\Gender;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Bill;
use App\Models\ClassEnrollment;
use App\Models\Payment;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "Rekap angsuran" (v6 D33): one row per bill of a semester with a cell per
 * instalment, up to the highest instalment limit among the semester's bills,
 * and what the mahasantri still owes from earlier semesters.
 *
 * Cells: paid (verified), pending (waiting for verification), open (not paid
 * yet, links to the bill), done (the bill is paid in fewer instalments) and
 * none (beyond this bill's own limit).
 */
final class InstallmentGrid
{
    /**
     * @return array{columns: int, rows: Collection<int, array{bill: Bill, class: string, cells: list<string>, arrears: int}>}
     */
    public static function build(Semester $semester, ?User $user, ?Role $role, ?int $classGroupId = null, string $search = '', ?Gender $gender = null): array
    {
        $term = '%'.mb_strtolower(trim($search)).'%';
        $classes = ClassEnrollment::query()->where('semester_id', $semester->id)->get(['student_id', 'class_group_id', 'class_name'])->keyBy('student_id');

        $bills = BillAccess::scope(Bill::query(), $user, $role)
            ->with(['student.user', 'payments' => fn ($query) => $query
                ->whereIn('status', [PaymentStatus::Verified, PaymentStatus::Unverified])
                ->orderBy('paid_on')->orderBy('id')
                ->select(['id', 'bill_id', 'status', 'paid_on'])])
            ->where('semester_id', $semester->id)
            ->where('status', '!=', BillStatus::Void)
            ->when($gender !== null, fn (Builder $query) => $query->whereHas('student', fn (Builder $query) => $query->where('gender', $gender)))
            ->when($classGroupId !== null, fn (Builder $query) => $query->whereIn('student_id', $classes->where('class_group_id', $classGroupId)->keys()))
            ->when(trim($search) !== '', fn (Builder $query) => $query->whereHas('student', fn (Builder $query) => $query
                ->whereRaw('lower(nim) like ?', [$term])
                ->orWhereHas('user', fn (Builder $query) => $query->whereRaw('lower(name) like ?', [$term]))))
            ->get();

        $columns = max(1, (int) $bills->max('max_installments'));
        $arrears = self::arrears($semester, $bills->map(fn (Bill $bill) => $bill->student_id)->values()->all());

        $rows = $bills
            ->map(fn (Bill $bill) => [
                'bill' => $bill,
                'class' => $classes->get($bill->student_id)->class_name ?? '—',
                'cells' => self::cells($bill, $columns),
                'arrears' => $arrears[$bill->student_id] ?? 0,
            ])
            ->sortBy(fn (array $row) => $row['class'].'|'.$row['bill']->student->nim)
            ->values();

        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * @return list<string>
     */
    private static function cells(Bill $bill, int $columns): array
    {
        /** @var Collection<int, Payment> $payments */
        $payments = $bill->payments->values();
        $cells = [];

        for ($slot = 0; $slot < $columns; $slot++) {
            $payment = $payments->get($slot);

            $cells[] = match (true) {
                $payment !== null => $payment->status === PaymentStatus::Verified ? 'paid' : 'pending',
                $slot >= $bill->max_installments => 'none',
                $bill->status === BillStatus::Paid => 'done',
                default => 'open',
            };
        }

        return $cells;
    }

    /**
     * What each student still owes on bills of earlier semesters.
     *
     * @param  array<int, int>  $studentIds
     * @return array<int, int> student id => amount
     */
    private static function arrears(Semester $semester, array $studentIds): array
    {
        $current = $semester->ordinal();
        $earlier = Semester::query()->with('academicYear')->get()
            ->filter(fn (Semester $other) => $other->ordinal() < $current)
            ->modelKeys();

        if ($studentIds === [] || $earlier === []) {
            return [];
        }

        return Bill::query()
            ->whereIn('student_id', $studentIds)
            ->whereIn('semester_id', $earlier)
            ->whereIn('status', [BillStatus::Unpaid, BillStatus::Partial])
            ->groupBy('student_id')
            ->selectRaw('student_id, sum(net_amount - paid_amount) as owed')
            ->pluck('owed', 'student_id')
            ->map(fn ($owed) => max(0, (int) $owed))
            ->all();
    }
}
