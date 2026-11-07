<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Actions\Finance\AdjustBill;
use App\Actions\Finance\GenerateBills;
use App\Actions\Finance\RecordPayment;
use App\Actions\Finance\ReviewPayments;
use App\Enums\BillStatus;
use App\Enums\Gender;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\BillResource;
use App\Models\Bill;
use App\Models\ClassEnrollment;
use App\Models\Payment;
use App\Models\Semester;
use App\Support\ActiveRole;
use App\Support\BillAccess;
use App\Support\CashBook;
use App\Support\InstallmentGrid;
use App\Support\Money;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Tagihan (Admin Keuangan, Superadmin; the Petugas Pembayaran for the
 * mahasantri of their gender): the bill list and the instalment grid, one
 * bill with its payments, recording a payment, generating the semester's
 * bills, and the corrections only the finance office or Superadmin make.
 */
#[Group('Keuangan', weight: 16)]
class BillController extends Controller
{
    /**
     * Bills.
     *
     * Of a semester (`?semester=`, default the active one; `0` for every semester), `status` (unpaid, partial,
     * paid, void, or `outstanding` for unpaid and partial), `search` on the bill number, NIM or name;
     * paginated. A Petugas Pembayaran sees only the bills of their scope.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['semester' => ['nullable', 'integer'], 'status' => ['nullable', 'string'], 'search' => ['nullable', 'string']]);
        $semesterId = $request->has('semester') ? $request->integer('semester') : Semester::active()?->id;
        $status = (string) $request->input('status');
        $term = '%'.mb_strtolower(trim((string) $request->input('search'))).'%';

        $bills = BillAccess::scope(Bill::query(), $request->user(), ActiveRole::get())
            ->with(['student.user', 'semester.academicYear'])
            ->when($semesterId !== 0, fn (Builder $query) => $query->where('semester_id', $semesterId))
            ->when($status === 'outstanding', fn (Builder $query) => $query->whereIn('status', [BillStatus::Unpaid, BillStatus::Partial]))
            ->when(! in_array($status, ['', 'outstanding'], true), fn (Builder $query) => $query->where('status', $status))
            ->when($request->filled('search'), fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereRaw('lower(number) like ?', [$term])
                ->orWhereHas('student', fn (Builder $query) => $query->whereRaw('lower(nim) like ?', [$term])
                    ->orWhereHas('user', fn (Builder $query) => $query->whereRaw('lower(name) like ?', [$term])))))
            ->orderBy('number')->paginate(25);
        $classes = ClassEnrollment::query()
            ->whereIn('semester_id', collect($bills->items())->pluck('semester_id')->unique())
            ->whereIn('student_id', collect($bills->items())->pluck('student_id')->unique())
            ->get(['student_id', 'semester_id', 'class_name'])->keyBy(fn (ClassEnrollment $enrollment) => $enrollment->student_id.'-'.$enrollment->semester_id);

        return response()->json([
            'data' => collect($bills->items())->map(fn (Bill $bill) => [
                'id' => $bill->id,
                'number' => $bill->number,
                'student_id' => $bill->student_id,
                'nim' => $bill->student->nim,
                'name' => $bill->student->user->name,
                'class_name' => $classes->get($bill->student_id.'-'.$bill->semester_id)?->class_name,
                'semester' => $bill->semester->label(),
                'net_amount' => $bill->net_amount,
                'paid_amount' => $bill->paid_amount,
                'outstanding' => $bill->outstanding(),
                'status' => $bill->status->value,
                'status_label' => $bill->status->label(),
            ])->values()->all(),
            'meta' => ['current_page' => $bills->currentPage(), 'last_page' => $bills->lastPage(), 'total' => $bills->total()],
        ]);
    }

    /**
     * Instalment grid (Rekap angsuran).
     *
     * One row per bill of a semester with a cell per instalment (paid, pending, open, done, none) up to the
     * semester's highest limit, and what is still owed from earlier semesters. Filters: `class`, `gender`
     * (finance office), `search`.
     */
    public function grid(Request $request): JsonResponse
    {
        $request->validate(['semester' => ['nullable', 'integer'], 'class' => ['nullable', 'integer'], 'gender' => ['nullable', 'string'], 'search' => ['nullable', 'string']]);
        $semester = $request->filled('semester') ? Semester::query()->findOrFail($request->integer('semester')) : Semester::active();
        abort_if($semester === null, 404);

        $grid = InstallmentGrid::build($semester, $request->user(), ActiveRole::get(), $request->integer('class') ?: null, (string) $request->input('search'), Gender::tryFrom((string) $request->input('gender')));

        return response()->json(['data' => [
            'semester' => $semester->label(),
            'columns' => $grid['columns'],
            'rows' => $grid['rows']->map(fn (array $row) => [
                'bill_id' => $row['bill']->id,
                'nim' => $row['bill']->student->nim,
                'name' => $row['bill']->student->user->name,
                'class_name' => $row['class'],
                'cells' => $row['cells'],
                'previous_semesters' => $row['arrears'],
                'status' => $row['bill']->status->value,
            ])->values()->all(),
        ]]);
    }

    /**
     * A bill.
     *
     * Lines and payments (with receipt links), what a new payment may be (`allowance`: open amount,
     * instalments used / allowed, minimum), and what this role may do.
     */
    public function show(Request $request, Bill $bill): JsonResponse
    {
        abort_unless(BillAccess::allows($request->user(), ActiveRole::get(), $bill), 403);
        $bill->load(['student.user', 'semester.academicYear', 'lines', 'payments']);

        return response()->json(['data' => [
            ...(new BillResource($bill))->resolve($request),
            'student' => ['id' => $bill->student_id, 'nim' => $bill->student->nim, 'name' => $bill->student->user->name],
            'max_installments' => $bill->max_installments,
            'allowance' => app(RecordPayment::class)->allowance($bill),
            'default_cash_account_id' => CashBook::defaultAccountId(PaymentMethod::Cash),
            'can' => [
                'record_payment' => $bill->status !== BillStatus::Void,
                'void' => BillAccess::administers(ActiveRole::get()),
                'change_installments' => ActiveRole::get() === Role::Superadmin,
                'void_payment' => ActiveRole::get() === Role::Superadmin,
            ],
        ]]);
    }

    /**
     * Record a payment.
     *
     * `amount`, `method` (cash or transfer), `paid_on`, `reference` (transfers), `proof` (multipart), and for the
     * finance office the `cash_account_id` it goes into. It waits for verification: cash is handed over, a
     * transfer is checked against the bank statement.
     */
    public function recordPayment(Request $request, Bill $bill, RecordPayment $record): JsonResponse
    {
        abort_unless(BillAccess::allows($request->user(), ActiveRole::get(), $bill), 403);
        $request->validate(['amount' => ['required', 'integer'], 'method' => ['required', 'string'], 'paid_on' => ['required', 'date'], 'reference' => ['nullable', 'string'], 'proof' => ['nullable', 'file'], 'cash_account_id' => ['nullable', 'integer']]);
        $role = ActiveRole::get();

        $payment = $record->handle($bill, $request->user(), [
            'amount' => $request->integer('amount'),
            'method' => $request->input('method'),
            'paid_on' => $request->input('paid_on'),
            'reference' => $request->input('reference') ?: null,
            'proof' => $request->file('proof'),
            // A Petugas Pembayaran hands cash over; the receiving Admin books it.
            'cash_account_id' => BillAccess::administers($role) ? ($request->integer('cash_account_id') ?: null) : null,
        ], BillAccess::source($role));

        return response()->json(['data' => ['payment_id' => $payment->id, 'status' => $payment->status->value]], 201);
    }

    /**
     * Generate the semester's bills.
     *
     * Without `apply: true` a preview: how many mahasantri are billed, the total, and how many have no fee. With
     * it, one bill per placed mahasantri (`due_on` optional); existing bills are kept.
     */
    public function generate(Request $request, Semester $semester, GenerateBills $generate): JsonResponse
    {
        abort_unless(BillAccess::administers(ActiveRole::get()), 403);
        $request->validate(['apply' => ['nullable', 'boolean'], 'due_on' => ['nullable', 'date']]);

        if (! $request->boolean('apply')) {
            $entries = $generate->preview($semester);

            return response()->json(['data' => [
                'students' => $entries->where('gross', '>', 0)->count(),
                'net' => (int) $entries->sum('net'),
                'net_label' => Money::format((int) $entries->sum('net')),
                'empty' => $entries->where('gross', 0)->count(),
            ]]);
        }

        return response()->json(['data' => ['created' => $generate->handle($semester, $request->input('due_on') ?: null)]]);
    }

    /**
     * Void a bill (finance office).
     *
     * Only a bill without payments, with a `reason`; generate it again afterwards.
     */
    public function void(Request $request, Bill $bill, AdjustBill $adjust): Response
    {
        abort_unless(BillAccess::administers(ActiveRole::get()), 403);
        $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $adjust->void($bill, (string) $request->input('reason'));

        return response()->noContent();
    }

    /**
     * Change the instalment limit (Superadmin).
     *
     * `max_installments` (up to 5) with a `reason`.
     */
    public function installments(Request $request, Bill $bill, AdjustBill $adjust): Response
    {
        abort_unless(ActiveRole::get() === Role::Superadmin, 403);
        $request->validate(['max_installments' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:500']]);
        $adjust->setMaxInstallments($bill, $request->integer('max_installments'), (string) $request->input('reason'));

        return response()->noContent();
    }

    /**
     * Void a payment (Superadmin).
     *
     * With a `reason`; the bill's paid amount and the cash book follow.
     */
    public function voidPayment(Request $request, Payment $payment, ReviewPayments $review): Response
    {
        abort_unless(ActiveRole::get() === Role::Superadmin, 403);
        $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $review->void($payment, (string) $request->input('reason'));

        return response()->noContent();
    }
}
