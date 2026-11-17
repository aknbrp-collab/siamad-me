<?php

namespace App\Support;

use App\Enums\BillStatus;
use App\Enums\FeeFrequency;
use App\Enums\Gender;
use App\Enums\Role;
use App\Models\Bill;
use App\Models\ClassEnrollment;
use App\Models\FeeType;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "Rekap angsuran" (v6 D33, by fee since after-review D58): one row per bill of a semester, and what the
 * mahasantri still owes from earlier semesters. Three views:
 *
 * - all fees (no fee chosen): each fee type as paid / left, and the bill's total left;
 * - a monthly fee: a cell per month of the bill line, then paid and left. A month is paid when what was
 *   verified on that fee covers it (the last month: the whole fee), pending when waiting payments cover it;
 * - another fee: paid, left and instalments used / allowed.
 *
 * "Left" is what is not verified yet (a waiting payment still counts as left, with a clock).
 * Cells: paid, pending, open (links to the bill) and none (beyond this bill's months).
 *
 * @phpstan-type Amounts array{due: int, paid: int, pending: int, left: int}
 * @phpstan-type Row array{bill: Bill, class: string, arrears: int, fees: array<int, Amounts>, due: int, paid: int, pending: int, left: int, cells: list<string>, used: int, max: int}
 */
final class InstallmentGrid
{
    public const ALL = 'all';

    public const MONTHLY = 'monthly';

    public const FEE = 'fee';

    /**
     * @return array{mode: string, fee: FeeType|null, columns: list<array{key: int, label: string}>, rows: Collection<int, Row>}
     */
    public static function build(Semester $semester, ?User $user, ?Role $role, ?int $classGroupId = null, string $search = '', ?Gender $gender = null, ?FeeType $fee = null): array
    {
        $term = '%'.mb_strtolower(trim($search)).'%';
        $classes = ClassEnrollment::query()->where('semester_id', $semester->id)->get(['student_id', 'class_group_id', 'class_name'])->keyBy('student_id');

        $bills = BillAccess::scope(Bill::query(), $user, $role)
            ->with(['student.user', 'lines'])
            ->where('semester_id', $semester->id)
            ->where('status', '!=', BillStatus::Void)
            ->when($fee !== null, fn (Builder $query) => $query->whereHas('lines', fn (Builder $query) => $query->where('fee_type_id', $fee?->id)->where('amount', '>', 0)))
            ->when($gender !== null, fn (Builder $query) => $query->whereHas('student', fn (Builder $query) => $query->where('gender', $gender)))
            ->when($classGroupId !== null, fn (Builder $query) => $query->whereIn('student_id', $classes->where('class_group_id', $classGroupId)->keys()))
            ->when(trim($search) !== '', fn (Builder $query) => $query->whereHas('student', fn (Builder $query) => $query
                ->whereRaw('lower(nim) like ?', [$term])
                ->orWhereHas('user', fn (Builder $query) => $query->whereRaw('lower(name) like ?', [$term]))))
            ->get();

        $mode = match (true) {
            $fee === null => self::ALL,
            $fee->frequency === FeeFrequency::Monthly => self::MONTHLY,
            default => self::FEE,
        };

        $feesByBill = BillFees::forBills($bills);
        $arrears = self::arrears($semester, $bills->map(fn (Bill $bill) => $bill->student_id)->values()->all());

        $rows = $bills->map(function (Bill $bill) use ($feesByBill, $fee, $classes, $arrears) {
            $byType = [];
            $chosen = ['due' => 0, 'paid' => 0, 'pending' => 0, 'left' => 0, 'used' => 0, 'max' => 0, 'months' => 0];

            foreach ($feesByBill[$bill->id] ?? [] as $item) {
                $type = (int) $item['line']->fee_type_id;
                $byType[$type] ??= ['due' => 0, 'paid' => 0, 'pending' => 0, 'left' => 0];
                $byType[$type]['due'] += $item['due'];
                $byType[$type]['paid'] += $item['paid'];
                $byType[$type]['pending'] += $item['pending'];
                $byType[$type]['left'] += $item['due'] - $item['paid'];

                if ($fee !== null && $type === $fee->id) {
                    $chosen['due'] += $item['due'];
                    $chosen['paid'] += $item['paid'];
                    $chosen['pending'] += $item['pending'];
                    $chosen['left'] += $item['due'] - $item['paid'];
                    $chosen['used'] = max($chosen['used'], $item['used']);
                    $chosen['max'] = max($chosen['max'], $item['max']);
                    $chosen['months'] = max($chosen['months'], (int) $item['line']->months);
                }
            }

            $all = $fee === null;

            return [
                'bill' => $bill,
                'class' => $classes->get($bill->student_id)->class_name ?? '—',
                'arrears' => $arrears[$bill->student_id] ?? 0,
                'fees' => $byType,
                'due' => $all ? array_sum(array_column($byType, 'due')) : $chosen['due'],
                'paid' => $all ? array_sum(array_column($byType, 'paid')) : $chosen['paid'],
                'pending' => $all ? array_sum(array_column($byType, 'pending')) : $chosen['pending'],
                'left' => $all ? array_sum(array_column($byType, 'left')) : $chosen['left'],
                'cells' => $chosen['months'] > 0 ? self::months($chosen['months'], $chosen['due'], $chosen['paid'], $chosen['pending']) : [],
                'used' => $chosen['used'],
                'max' => $chosen['max'],
            ];
        })
            ->sortBy(fn (array $row) => $row['class'].'|'.$row['bill']->student->nim)
            ->values();

        return ['mode' => $mode, 'fee' => $fee, 'columns' => self::columns($mode, $rows), 'rows' => self::pad($rows)];
    }

    /**
     * The fee types billed in a semester (for the filter), in name order.
     *
     * @return Collection<int, FeeType>
     */
    public static function feeTypes(Semester $semester): Collection
    {
        $ids = Bill::query()->where('semester_id', $semester->id)->where('status', '!=', BillStatus::Void)
            ->join('bill_lines', 'bill_lines.bill_id', '=', 'bills.id')
            ->where('bill_lines.amount', '>', 0)->whereNotNull('bill_lines.fee_type_id')
            ->distinct()->pluck('bill_lines.fee_type_id');

        return FeeType::query()->whereKey($ids)->orderBy('name')->get();
    }

    /**
     * A cell per month: paid when verified money covers it, pending when waiting money does, else open.
     *
     * @return list<string>
     */
    private static function months(int $months, int $due, int $paid, int $pending): array
    {
        $monthly = intdiv($due, $months);
        $cells = [];

        for ($month = 1; $month <= $months; $month++) {
            $needed = $month === $months ? $due : $month * $monthly;
            $cells[] = match (true) {
                $paid >= $needed => 'paid',
                $paid + $pending >= $needed => 'pending',
                default => 'open',
            };
        }

        return $cells;
    }

    /**
     * Month cells up to the most months of any row ("none" beyond a bill's own months).
     *
     * @param  Collection<int, Row>  $rows
     * @return Collection<int, Row>
     */
    private static function pad(Collection $rows): Collection
    {
        $width = (int) $rows->max(fn (array $row) => count($row['cells']));

        return $rows->map(fn (array $row) => [...$row, 'cells' => array_pad($row['cells'], $row['cells'] === [] ? 0 : $width, 'none')]);
    }

    /**
     * @param  Collection<int, Row>  $rows
     * @return list<array{key: int, label: string}>
     */
    private static function columns(string $mode, Collection $rows): array
    {
        if ($mode === self::MONTHLY) {
            $width = (int) $rows->max(fn (array $row) => count($row['cells']));

            return array_map(fn (int $month) => ['key' => $month, 'label' => (string) $month], $width > 0 ? range(1, $width) : []);
        }

        if ($mode === self::FEE) {
            return [];
        }

        $types = $rows->flatMap(fn (array $row) => array_keys($row['fees']))->unique()->sort()->values();
        $names = FeeType::query()->whereKey($types->all())->pluck('name', 'id');

        return array_values($types->map(fn (int $type) => ['key' => $type, 'label' => (string) ($names[$type] ?? __('Other fees'))])->all());
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
