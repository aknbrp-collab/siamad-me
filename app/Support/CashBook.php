<?php

namespace App\Support;

use App\Enums\CashAccountType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\CashAccount;
use App\Models\CashEntry;
use App\Models\Payment;
use App\Models\Setting;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reading the cash book (v3 D8): balances, the ledger of an account and the
 * income/expense summary. Money in an account = its opening balance + the
 * verified tuition payments booked to it + other income − expenses ±
 * transfers, counting only what is dated on or after its opening date.
 */
final class CashBook
{
    /**
     * The account a payment goes into when nobody chose one: the default for
     * its method (Settings on the accounts page), if set.
     */
    public static function defaultAccountId(PaymentMethod $method): ?int
    {
        $id = Setting::get($method === PaymentMethod::Cash ? 'cashbook.default_cash_account' : 'cashbook.default_bank_account');

        return is_numeric($id) && CashAccount::query()->whereKey((int) $id)->where('is_active', true)->exists() ? (int) $id : null;
    }

    /**
     * Entries dated up to and including this day are closed (null: none).
     */
    public static function closedThrough(): ?Carbon
    {
        $date = Setting::get('cashbook.closed_through');

        return is_string($date) ? Carbon::parse($date)->endOfDay() : null;
    }

    /**
     * Balance at the start of $before (exclusive), or now.
     */
    public static function balance(CashAccount $account, ?CarbonInterface $before = null): int
    {
        $payments = Payment::query()
            ->where('cash_account_id', $account->id)
            ->where('status', PaymentStatus::Verified)
            ->whereDate('paid_on', '>=', $account->opening_date)
            ->when($before, fn ($query) => $query->whereDate('paid_on', '<', $before))
            ->sum('amount');

        $entries = CashEntry::query()->counting()
            ->where('cash_account_id', $account->id)
            ->whereDate('entry_date', '>=', $account->opening_date)
            ->when($before, fn ($query) => $query->whereDate('entry_date', '<', $before))
            ->get(['direction', 'amount'])
            ->sum(fn (CashEntry $entry) => $entry->signedAmount());

        // The opening balance is what the account held at the start of its opening day.
        $opening = $before === null || $before->gte($account->opening_date->copy()->startOfDay()) ? $account->opening_balance : 0;

        return $opening + (int) $payments + (int) $entries;
    }

    /**
     * Every movement of an account between two dates, oldest first, with the
     * running balance.
     *
     * Each row: date, number, description, party, category, in, out, balance, entry (CashEntry|null), payment (Payment|null).
     *
     * @return array{start: int, end: int, in: int, out: int, rows: list<array<string, mixed>>}
     */
    public static function ledger(CashAccount $account, CarbonInterface $from, CarbonInterface $to): array
    {
        $from = $from->copy()->startOfDay()->max($account->opening_date->copy()->startOfDay());
        $start = self::balance($account, $from);

        $payments = Payment::query()
            ->with('bill.student.user')
            ->where('cash_account_id', $account->id)
            ->where('status', PaymentStatus::Verified)
            ->whereDate('paid_on', '>=', $from)
            ->whereDate('paid_on', '<=', $to)
            ->get()
            ->map(fn (Payment $payment) => [
                'date' => $payment->paid_on,
                'number' => (string) ($payment->receipt_number ?? $payment->bill->number),
                'description' => __('Payment of bill :number', ['number' => $payment->bill->number]),
                'party' => $payment->bill->student->user->name,
                'category' => __('Tuition payments'),
                'in' => $payment->amount,
                'out' => 0,
                'entry' => null,
                'payment' => $payment,
                'sort' => $payment->verified_at->timestamp ?? 0,
            ]);

        $entries = CashEntry::query()->counting()
            ->with('category')
            ->where('cash_account_id', $account->id)
            ->whereDate('entry_date', '>=', $from)
            ->whereDate('entry_date', '<=', $to)
            ->get()
            ->map(fn (CashEntry $entry) => [
                'date' => $entry->entry_date,
                'number' => $entry->number,
                'description' => $entry->description,
                'party' => $entry->party,
                'category' => $entry->category->name ?? $entry->type->label(),
                'in' => $entry->direction === 'in' ? $entry->amount : 0,
                'out' => $entry->direction === 'out' ? $entry->amount : 0,
                'entry' => $entry,
                'payment' => null,
                'sort' => $entry->created_at->timestamp,
            ]);

        $balance = $start;
        $rows = $payments->concat($entries)
            ->sortBy([['date', 'asc'], ['sort', 'asc']])
            ->values()
            ->map(function (array $row) use (&$balance) {
                $balance += $row['in'] - $row['out'];
                unset($row['sort']);

                return [...$row, 'balance' => $balance];
            });

        return [
            'start' => $start,
            'end' => $balance,
            'in' => (int) $rows->sum('in'),
            'out' => (int) $rows->sum('out'),
            'rows' => array_values($rows->all()),
        ];
    }

    /**
     * Income and expense by category between two dates, over all accounts.
     * Transfers move money between accounts and are not income or expense.
     *
     * @return array{tuition: int, income: Collection<string, int>, expense: Collection<string, int>, total_in: int, total_out: int, unassigned: int}
     */
    public static function summary(CarbonInterface $from, CarbonInterface $to): array
    {
        $tuition = Payment::query()
            ->where('status', PaymentStatus::Verified)
            ->whereDate('paid_on', '>=', $from)
            ->whereDate('paid_on', '<=', $to)
            ->get(['amount', 'cash_account_id']);

        $entries = CashEntry::query()->counting()
            ->with('category')
            ->whereIn('type', ['income', 'expense'])
            ->whereDate('entry_date', '>=', $from)
            ->whereDate('entry_date', '<=', $to)
            ->get();

        $byCategory = fn (string $type) => $entries->where('type.value', $type)
            ->groupBy(fn (CashEntry $entry) => $entry->category->name ?? __('Uncategorised'))
            ->map(fn (Collection $group) => (int) $group->sum('amount'))
            ->sortKeys();

        $income = $byCategory('income');
        $expense = $byCategory('expense');

        return [
            'tuition' => (int) $tuition->sum('amount'),
            'income' => $income,
            'expense' => $expense,
            'total_in' => (int) $tuition->sum('amount') + (int) $income->sum(),
            'total_out' => (int) $expense->sum(),
            // Verified tuition not booked to any account yet (paid before the cash book).
            'unassigned' => (int) $tuition->whereNull('cash_account_id')->sum('amount'),
        ];
    }

    /**
     * Active accounts with their current balance.
     *
     * @return Collection<int, array{account: CashAccount, balance: int}>
     */
    public static function balances(): Collection
    {
        return CashAccount::query()->where('is_active', true)->orderByDesc('type')->orderBy('name')->get()
            ->map(fn (CashAccount $account) => ['account' => $account, 'balance' => self::balance($account)]);
    }

    /**
     * Accounts a payment of this method may go into, defaults first.
     *
     * @return Collection<int, CashAccount>
     */
    public static function accountsFor(PaymentMethod $method): Collection
    {
        $preferred = $method === PaymentMethod::Cash ? CashAccountType::Cash : CashAccountType::Bank;

        return CashAccount::query()->where('is_active', true)->get()
            ->sortBy(fn (CashAccount $account) => [$account->type === $preferred ? 0 : 1, $account->name])
            ->values();
    }
}
