<?php

namespace App\Support;

use App\Enums\PaymentStatus;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\PaymentAllocation;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Each fee of a bill paid on its own (after-review D55). A fee is a bill line with a positive amount; a
 * scholarship discount is shared by the fees in proportion to their amounts. For each fee: what it is due,
 * paid (verified), pending (waiting for verification), left, and payments used of its limit.
 *
 * @phpstan-type Fee array{line: BillLine, due: int, paid: int, pending: int, left: int, used: int, max: int}
 */
final class BillFees
{
    /**
     * The fees of a bill in the bill's order, keyed by bill line id.
     *
     * @return Collection<int, Fee>
     */
    public static function of(Bill $bill): Collection
    {
        return self::forBills(collect([$bill]))[$bill->id];
    }

    /**
     * The fees of many bills with one query for their payments (the grid).
     *
     * @param  Collection<int, Bill>  $bills
     * @return array<int, Collection<int, Fee>> bill id => fees
     */
    public static function forBills(Collection $bills): array
    {
        if ($bills->isEmpty()) {
            return [];
        }

        (new EloquentCollection($bills->all()))->loadMissing('lines');

        $allocations = PaymentAllocation::query()
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->whereIn('payments.bill_id', $bills->map(fn (Bill $bill) => $bill->id)->all())
            ->whereIn('payments.status', PaymentStatus::counting())
            ->get(['payment_allocations.bill_line_id', 'payment_allocations.payment_id', 'payment_allocations.amount', 'payments.status'])
            ->groupBy('bill_line_id')
            ->all();

        $result = [];
        foreach ($bills as $bill) {
            $result[$bill->id] = self::compute($bill, $allocations);
        }

        return $result;
    }

    /**
     * @param  array<int|string, EloquentCollection<int, PaymentAllocation>>  $allocations  by bill line id
     * @return Collection<int, Fee>
     */
    private static function compute(Bill $bill, array $allocations): Collection
    {
        $fees = $bill->lines->filter(fn (BillLine $line) => $line->amount > 0)->sortBy('id')->values();
        $discount = (int) -$bill->lines->filter(fn (BillLine $line) => $line->amount < 0)->sum('amount');
        $shares = self::shares($fees->mapWithKeys(fn (BillLine $line) => [$line->id => $line->amount])->all(), $discount);

        return $fees->mapWithKeys(function (BillLine $line) use ($bill, $shares, $allocations) {
            $mine = $allocations[$line->id] ?? new EloquentCollection;
            $paid = (int) $mine->filter(fn (PaymentAllocation $row) => self::status($row) === PaymentStatus::Verified)->sum('amount');
            $pending = (int) $mine->filter(fn (PaymentAllocation $row) => self::status($row) === PaymentStatus::Unverified)->sum('amount');
            $due = max(0, $line->amount - ($shares[$line->id] ?? 0));

            return [$line->id => [
                'line' => $line,
                'due' => $due,
                'paid' => $paid,
                'pending' => $pending,
                'left' => max(0, $due - $paid - $pending),
                'used' => $mine->pluck('payment_id')->unique()->count(),
                'max' => max(1, $line->max_installments ?? $bill->max_installments),
            ]];
        });
    }

    /**
     * What is left to pay on fees that can still take a payment.
     */
    public static function open(Bill $bill): int
    {
        return (int) self::of($bill)->filter(fn (array $fee) => $fee['used'] < $fee['max'])->sum('left');
    }

    /**
     * Spread an amount over the fees in the bill's order: each fee that can still take a payment gets up to what
     * is left of it. Used when a payment does not say which fees it pays (a transfer confirmation, the app).
     *
     * @return array<int, int> bill line id => amount
     */
    public static function spread(Bill $bill, int $amount): array
    {
        $rest = $amount;
        $allocations = [];

        foreach (self::of($bill) as $id => $fee) {
            if ($rest <= 0) {
                break;
            }

            if ($fee['left'] > 0 && $fee['used'] < $fee['max']) {
                $take = min($rest, $fee['left']);
                $allocations[$id] = $take;
                $rest -= $take;
            }
        }

        if ($rest > 0) {
            throw ValidationException::withMessages(['amount' => __('The amount is more than what is left (:open).', ['open' => Money::format(self::open($bill))])]);
        }

        return $allocations;
    }

    /**
     * The rules of each fee for a new payment (D55): at most its number of payments, never more than what is
     * left of it, at least the minimum instalment unless it settles the fee or (a monthly fee) brings it to a
     * whole number of months paid, and the last allowed payment settles it. Errors go under
     * "allocations.{line id}", or under $key for a spread payment.
     *
     * @param  array<int, int>  $allocations  bill line id => amount
     */
    public static function check(Bill $bill, array $allocations, ?string $key = null): void
    {
        $fees = self::of($bill);
        $min = (int) Setting::get('payment.min_installment_amount', 200000);
        $errors = [];

        if (array_filter($allocations) === []) {
            throw ValidationException::withMessages([$key ?? 'allocations' => __('Choose at least one fee to pay.')]);
        }

        foreach ($allocations as $id => $amount) {
            $field = $key ?? "allocations.{$id}";
            $fee = $fees->get($id);

            if ($fee === null) {
                $errors[$field][] = __('This fee is not on the bill.');

                continue;
            }

            $name = $fee['line']->description;
            $left = Money::format($fee['left']);

            $message = match (true) {
                $amount < 1 => __('Enter an amount for :fee.', ['fee' => $name]),
                $fee['used'] >= $fee['max'] => __(':fee already has its maximum of :max payments.', ['fee' => $name, 'max' => $fee['max']]),
                $amount > $fee['left'] => __('The amount for :fee is more than what is left of it (:left).', ['fee' => $name, 'left' => $left]),
                $fee['max'] === $fee['used'] + 1 && $amount !== $fee['left'] => __('This is the last allowed payment for :fee; it must settle it (:left).', ['fee' => $name, 'left' => $left]),
                $amount < $min && $amount !== $fee['left'] && ! self::wholeMonths($fee, $amount) => self::monthly($fee) !== null
                    ? __('A payment for :fee must be at least :min, unless it pays whole months (:monthly a month) or settles it (:left).', ['fee' => $name, 'min' => Money::format($min), 'monthly' => Money::format((int) self::monthly($fee)), 'left' => $left])
                    : __('A payment for :fee must be at least :min, unless it settles it (:left).', ['fee' => $name, 'min' => Money::format($min), 'left' => $left]),
                default => null,
            };

            if ($message !== null) {
                $errors[$field][] = $message;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * A monthly fee's amount for one month (its due over its months), null for other fees.
     *
     * @param  Fee  $fee
     */
    public static function monthly(array $fee): ?int
    {
        $months = (int) $fee['line']->months;
        $monthly = $months > 0 ? intdiv($fee['due'], $months) : 0;

        return $monthly > 0 ? $monthly : null;
    }

    /**
     * Whether a payment on a monthly fee leaves it paid for a whole number of months (counting what is already
     * paid or waiting), so it may be below the minimum instalment (after-review: monthly fees paid month by month).
     *
     * @param  Fee  $fee
     */
    private static function wholeMonths(array $fee, int $amount): bool
    {
        $monthly = self::monthly($fee);

        return $monthly !== null && ($fee['paid'] + $fee['pending'] + $amount) % $monthly === 0;
    }

    /**
     * A discount shared by amounts in proportion (largest remainder, so the shares add up exactly).
     *
     * @param  array<int, int>  $amounts  key => amount
     * @return array<int, int> key => share
     */
    public static function shares(array $amounts, int $discount): array
    {
        $total = array_sum($amounts);

        if ($discount <= 0 || $total <= 0) {
            return array_map(fn () => 0, $amounts);
        }

        $shares = [];
        $remainders = [];

        foreach ($amounts as $key => $amount) {
            $exact = min($discount, $total) * $amount / $total;
            $shares[$key] = (int) floor($exact);
            $remainders[$key] = $exact - floor($exact);
        }

        $missing = min($discount, $total) - array_sum($shares);
        arsort($remainders);

        foreach (array_keys($remainders) as $key) {
            if ($missing-- <= 0) {
                break;
            }
            $shares[$key]++;
        }

        return $shares;
    }

    private static function status(PaymentAllocation $row): ?PaymentStatus
    {
        // The payment's status, joined in.
        $status = $row->getAttribute('status');

        return $status instanceof PaymentStatus ? $status : PaymentStatus::tryFrom((string) $status);
    }
}
