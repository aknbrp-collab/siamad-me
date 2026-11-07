<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Actions\Finance\ManageCashBook;
use App\Enums\BillStatus;
use App\Enums\HandoverStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\CashAccount;
use App\Models\CashCategory;
use App\Models\CashEntry;
use App\Models\ClassEnrollment;
use App\Models\Payment;
use App\Models\PaymentHandover;
use App\Models\Semester;
use App\Support\ActiveRole;
use App\Support\ApiFiles;
use App\Support\CashBook;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Buku kas (Admin Keuangan, Superadmin): accounts (cash, bank) and their
 * balances, the ledger, income and expenses, transfers between accounts,
 * voiding, closing the books, the cash report, and the finance overview.
 */
#[Group('Keuangan', weight: 16)]
class CashBookController extends Controller
{
    /**
     * Finance overview.
     *
     * For a semester (`?semester=`, default the active one): billed, collected (verified), waiting for
     * verification and still owed, per class, the ten largest arrears, and what waits to be verified.
     */
    public function overview(Request $request): JsonResponse
    {
        $request->validate(['semester' => ['nullable', 'integer']]);
        $semesterId = $request->integer('semester') ?: (Semester::active()->id ?? Semester::query()->orderByDesc('id')->value('id'));
        $bills = Bill::query()->with('student.user')->where('semester_id', $semesterId)->where('status', '!=', BillStatus::Void)->get();
        $classOf = ClassEnrollment::query()->where('semester_id', $semesterId)->pluck('class_name', 'student_id');

        return response()->json(['data' => [
            'semester_id' => $semesterId,
            'billed' => (int) $bills->sum('net_amount'),
            'collected' => (int) $bills->sum('paid_amount'),
            'pending' => (int) Payment::query()->where('status', PaymentStatus::Unverified)->whereIn('bill_id', $bills->modelKeys())->sum('amount'),
            'outstanding' => (int) $bills->sum(fn (Bill $bill) => $bill->outstanding()),
            'counts' => $bills->countBy(fn (Bill $bill) => $bill->status->value)->all(),
            'by_class' => $bills->groupBy(fn (Bill $bill) => (string) ($classOf[$bill->student_id] ?? __('No class')))
                ->map(fn ($group, $class) => [
                    'class' => (string) $class,
                    'bills' => $group->count(),
                    'billed' => (int) $group->sum('net_amount'),
                    'collected' => (int) $group->sum('paid_amount'),
                    'outstanding' => (int) $group->sum(fn (Bill $bill) => $bill->outstanding()),
                ])->sortBy('class')->values()->all(),
            'largest_arrears' => $bills->filter(fn (Bill $bill) => $bill->outstanding() > 0)->sortByDesc(fn (Bill $bill) => $bill->outstanding())->take(10)->values()
                ->map(fn (Bill $bill) => ['bill_id' => $bill->id, 'nim' => $bill->student->nim, 'name' => $bill->student->user->name, 'outstanding' => $bill->outstanding()])->all(),
            'waiting' => [
                'handovers' => PaymentHandover::query()->where('status', HandoverStatus::Submitted)->count(),
                'cash_not_handed_over' => Payment::query()->where('status', PaymentStatus::Unverified)->where('method', PaymentMethod::Cash)->whereNull('payment_handover_id')->count(),
                'transfers' => Payment::query()->where('status', PaymentStatus::Unverified)->where('method', PaymentMethod::Transfer)->count(),
            ],
        ]]);
    }

    /**
     * Accounts and categories.
     *
     * Accounts with their balance today, the default cash and bank accounts, income and expense categories, the
     * month the books are closed through, and verified payments not booked to an account yet.
     */
    public function accounts(): JsonResponse
    {
        return response()->json(['data' => [
            'accounts' => CashBook::balances()->map(fn (array $row) => [
                ...$row['account']->only(['id', 'name', 'account_number', 'opening_balance', 'is_active']),
                'type' => $row['account']->type->value,
                'opening_date' => $row['account']->opening_date->toDateString(),
                'balance' => $row['balance'],
            ])->values()->all(),
            'default_cash_account_id' => CashBook::defaultAccountId(PaymentMethod::Cash),
            'default_bank_account_id' => CashBook::defaultAccountId(PaymentMethod::Transfer),
            'categories' => CashCategory::query()->orderBy('kind')->orderBy('name')->get(['id', 'kind', 'name', 'is_active'])->toArray(),
            'closed_through' => CashBook::closedThrough()?->toDateString(),
            'unbooked' => Payment::query()->whereNull('cash_account_id')->where('status', PaymentStatus::Verified)->get(['method', 'amount'])
                ->groupBy(fn (Payment $payment) => $payment->method->value)
                ->map(fn ($group) => ['count' => $group->count(), 'amount' => (int) $group->sum('amount')])->all(),
        ]]);
    }

    /**
     * Ledger.
     *
     * One account's entries in a `month` (YYYY-MM, default this month): opening and closing balance, money in
     * and out, and each row with its running balance.
     */
    public function ledger(Request $request, CashAccount $account): JsonResponse
    {
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $start = Carbon::parse(($request->input('month') ?: now()->format('Y-m')).'-01')->startOfMonth();
        $ledger = CashBook::ledger($account, $start, $start->copy()->endOfMonth());
        $user = $request->user();
        $role = ActiveRole::get();

        return response()->json(['data' => [
            'start' => $ledger['start'],
            'end' => $ledger['end'],
            'in' => $ledger['in'],
            'out' => $ledger['out'],
            'rows' => array_map(function (array $row) use ($user, $role) {
                $entry = $row['entry'] instanceof CashEntry ? $row['entry'] : null;
                $payment = $row['payment'] instanceof Payment ? $row['payment'] : null;

                return [
                    'date' => $row['date'] instanceof \DateTimeInterface ? $row['date']->format('Y-m-d') : $row['date'],
                    'number' => $row['number'],
                    'description' => $row['description'],
                    'party' => $row['party'],
                    'category' => $row['category'],
                    'in' => $row['in'],
                    'out' => $row['out'],
                    'balance' => $row['balance'],
                    'entry_id' => $entry?->id,
                    'voided' => $entry?->voided_at !== null,
                    'payment_id' => $payment?->id,
                    'proof_url' => $entry?->proof_path === null ? null : ApiFiles::url($user, $role, 'cash-proof', $entry->id),
                ];
            }, $ledger['rows']),
        ]]);
    }

    /**
     * Income and expense summary.
     *
     * From `from` to `to` (YYYY-MM): tuition, other income and expenses per category, and a link to the cash
     * report PDF (with the Bendahara's signature).
     */
    public function summary(Request $request): JsonResponse
    {
        $request->validate(['from' => ['nullable', 'date_format:Y-m'], 'to' => ['nullable', 'date_format:Y-m']]);
        $from = (string) ($request->input('from') ?: now()->format('Y-m'));
        $to = (string) ($request->input('to') ?: $from);
        $summary = CashBook::summary(Carbon::parse($from.'-01')->startOfMonth(), Carbon::parse($to.'-01')->endOfMonth());

        return response()->json(['data' => [
            'tuition' => $summary['tuition'],
            'income' => $summary['income']->all(),
            'expense' => $summary['expense']->all(),
            'total_in' => $summary['total_in'],
            'total_out' => $summary['total_out'],
            'unassigned' => $summary['unassigned'],
            'report_url' => ApiFiles::download($request->user(), ActiveRole::get(), 'cash-report', ['from' => $from, 'to' => $to]),
        ]]);
    }

    /**
     * Record income or an expense.
     *
     * Multipart: `type` (income or expense), `cash_account_id`, `entry_date`, `amount`, `cash_category_id`,
     * `party`, `description`, `proof` (receipt photo). Numbered automatically; not in a closed month.
     */
    public function record(Request $request, ManageCashBook $book): JsonResponse
    {
        $request->validate(['type' => ['required', 'string'], 'cash_account_id' => ['required', 'integer'], 'entry_date' => ['required', 'date'], 'amount' => ['required', 'integer'], 'cash_category_id' => ['nullable', 'integer'], 'party' => ['nullable', 'string'], 'description' => ['required', 'string'], 'proof' => ['nullable', 'file']]);

        $entry = $book->record([
            ...$request->only(['type', 'cash_account_id', 'entry_date', 'amount', 'cash_category_id', 'party']),
            'description' => trim((string) $request->input('description')),
            'proof' => $request->file('proof'),
        ], $request->user());

        return response()->json(['data' => ['id' => $entry->id, 'number' => $entry->number]], 201);
    }

    /**
     * Move money between accounts.
     *
     * `from_account_id`, `to_account_id`, `entry_date`, `amount`, `description` (e.g. depositing cash in the bank).
     */
    public function transfer(Request $request, ManageCashBook $book): JsonResponse
    {
        $request->validate(['from_account_id' => ['required', 'integer'], 'to_account_id' => ['required', 'integer'], 'entry_date' => ['required', 'date'], 'amount' => ['required', 'integer'], 'description' => ['required', 'string']]);
        $entries = $book->transfer([
            ...$request->only(['from_account_id', 'to_account_id', 'entry_date', 'amount']),
            'description' => trim((string) $request->input('description')),
        ], $request->user());

        return response()->json(['data' => ['entry_ids' => array_map(fn (CashEntry $entry) => $entry->id, $entries)]], 201);
    }

    /**
     * Void an entry.
     *
     * With a `reason`; it stays in the ledger, struck through. Not in a closed month.
     */
    public function void(Request $request, CashEntry $entry, ManageCashBook $book): Response
    {
        $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $book->void($entry, (string) $request->input('reason'), $request->user());

        return response()->noContent();
    }

    /**
     * Close the books.
     *
     * Through `month` (YYYY-MM): entries dated in it or before can no longer be added or voided.
     */
    public function close(Request $request, ManageCashBook $book): JsonResponse
    {
        $request->validate(['month' => ['required', 'date_format:Y-m']]);

        return response()->json(['data' => ['closed_through' => $book->closeThrough((string) $request->input('month'))->toDateString()]]);
    }

    /**
     * Add or change an account.
     *
     * `name`, `type` (cash or bank), `account_number`, `opening_balance`, `opening_date`, `is_active`.
     */
    public function saveAccount(Request $request, ManageCashBook $book): JsonResponse
    {
        $request->validate(['id' => ['nullable', 'integer'], 'name' => ['required', 'string'], 'type' => ['required', 'string'], 'account_number' => ['nullable', 'string'], 'opening_balance' => ['required', 'integer'], 'opening_date' => ['required', 'date'], 'is_active' => ['nullable', 'boolean']]);
        $account = $book->saveAccount($request->filled('id') ? CashAccount::query()->findOrFail($request->integer('id')) : null, [
            ...$request->only(['name', 'type', 'account_number', 'opening_balance', 'opening_date']),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json(['data' => ['id' => $account->id]]);
    }

    /**
     * Set the default accounts.
     *
     * Where verified cash and transfers go when no account is chosen.
     */
    public function defaults(Request $request, ManageCashBook $book): Response
    {
        $request->validate(['cash_account_id' => ['nullable', 'integer'], 'bank_account_id' => ['nullable', 'integer']]);
        $book->setDefaults($request->integer('cash_account_id') ?: null, $request->integer('bank_account_id') ?: null);

        return response()->noContent();
    }

    /**
     * Add or change a category.
     *
     * `kind` (income or expense), `name`, `is_active`.
     */
    public function saveCategory(Request $request, ManageCashBook $book): JsonResponse
    {
        $request->validate(['id' => ['nullable', 'integer'], 'kind' => ['required', Rule::in(['income', 'expense'])], 'name' => ['required', 'string'], 'is_active' => ['nullable', 'boolean']]);
        $category = $book->saveCategory(
            $request->filled('id') ? CashCategory::query()->findOrFail($request->integer('id')) : null,
            (string) $request->input('kind'), (string) $request->input('name'), $request->boolean('is_active', true),
        );

        return response()->json(['data' => ['id' => $category->id]]);
    }

    /**
     * Book verified payments to an account.
     *
     * Verified payments of `method` (cash or transfer) without an account go into `cash_account_id`.
     */
    public function assignUnbooked(Request $request, ManageCashBook $book): JsonResponse
    {
        $request->validate(['method' => ['required', Rule::enum(PaymentMethod::class)], 'cash_account_id' => ['required', 'integer']]);

        return response()->json(['data' => ['booked' => $book->assignUnbooked(PaymentMethod::from((string) $request->input('method')), CashAccount::query()->findOrFail($request->integer('cash_account_id')))]]);
    }
}
