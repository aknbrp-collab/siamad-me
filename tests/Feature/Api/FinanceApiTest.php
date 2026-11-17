<?php

use App\Actions\Enrollment\ManageClassEnrollments;
use App\Actions\Finance\RecordPayment;
use App\Actions\People\ManageUserAccounts;
use App\Enums\Gender;
use App\Enums\PaymentSource;
use App\Enums\Role;
use App\Models\Bill;
use App\Models\ClassGroup;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->semester = Semester::factory()->active()->create();
    $putra = ClassGroup::factory()->create();
    $putri = ClassGroup::factory()->putri()->create();
    $this->ahmad = Student::factory()->create();
    $this->aisyah = Student::factory()->female()->create();
    app(ManageClassEnrollments::class)->enroll($this->semester, $putra, [$this->ahmad->id]);
    app(ManageClassEnrollments::class)->enroll($this->semester, $putri, [$this->aisyah->id]);

    $this->cashier = User::factory()->withRole(Role::AdminPayment)->create();
    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->superadmin = User::factory()->withRole(Role::Superadmin)->create();
    $this->fee = FeeType::factory()->create();
});

/**
 * Act through a device token in a role (created directly, not through the rate-limited login).
 */
function asFinance(User $user, Role $role): TestCase
{
    app('auth')->forgetGuards();

    return test()->withToken($user->createToken('Test')->plainTextToken)->withHeader('X-Role', $role->value);
}

function financeBillOf(Student $student): Bill
{
    return Bill::query()->where('student_id', $student->id)->sole();
}

/**
 * A rate of 2,000,000 for the semester's year, then the bills.
 */
function financeBills(): void
{
    asFinance(test()->cashier, Role::AdminPayment)->postJson('/api/v1/finance/fee-rates', [
        'academic_year_id' => test()->semester->academic_year_id, 'fee_type_id' => test()->fee->id, 'amount' => 2_000_000,
    ])->assertOk();
    asFinance(test()->cashier, Role::AdminPayment)->postJson('/api/v1/finance/semesters/'.test()->semester->id.'/bills', ['apply' => true])->assertOk()->assertJsonPath('data.created', 2);
}

test('fees, a duplicate rate, generating bills with a preview, and scholarships', function () {
    $type = asFinance($this->cashier, Role::AdminPayment)->postJson('/api/v1/finance/fee-types', ['name' => 'Uang Kitab', 'frequency' => 'per_semester'])->assertOk()->json('data.id');
    $rate = ['academic_year_id' => $this->semester->academic_year_id, 'fee_type_id' => $type, 'amount' => 150_000];
    asFinance($this->cashier, Role::AdminPayment)->postJson('/api/v1/finance/fee-rates', $rate)->assertOk();
    asFinance($this->cashier, Role::AdminPayment)->postJson('/api/v1/finance/fee-rates', $rate)->assertUnprocessable()->assertJsonValidationErrors('amount');

    asFinance($this->cashier, Role::AdminPayment)->postJson("/api/v1/finance/semesters/{$this->semester->id}/bills")->assertOk()
        ->assertJsonPath('data.students', 2)->assertJsonPath('data.net', 300_000);

    $scholarship = asFinance($this->cashier, Role::AdminPayment)->postJson('/api/v1/finance/scholarships', [
        'academic_year_id' => $this->semester->academic_year_id, 'name' => 'Beasiswa Tahfidz', 'funding_source' => 'Yayasan', 'type' => 'percent', 'value' => 50,
    ])->assertOk()->json('data.id');
    $award = asFinance($this->cashier, Role::AdminPayment)->postJson("/api/v1/finance/scholarships/{$scholarship}/awards", ['student_id' => $this->ahmad->id, 'semester_id' => $this->semester->id])->assertCreated()->json('data.id');
    asFinance($this->cashier, Role::AdminPayment)->postJson("/api/v1/finance/semesters/{$this->semester->id}/bills", ['apply' => true])->assertOk();
    expect(financeBillOf($this->ahmad)->net_amount)->toBe(75_000);
    asFinance($this->cashier, Role::AdminPayment)->getJson("/api/v1/finance/scholarships?scholarship={$scholarship}")->assertOk()->assertJsonPath('data.awards.0.id', $award);

    // Not for the Petugas Pembayaran or Admin.
    asFinance($this->admin, Role::Admin)->getJson('/api/v1/finance/fees')->assertForbidden();
});

test('bills: list, grid and one bill; a collector sees only their gender; corrections by role', function () {
    financeBills();
    app(ManageUserAccounts::class)->setCollector($this->aisyah->user, Gender::Female);
    $collector = $this->aisyah->user->fresh();

    asFinance($this->cashier, Role::AdminPayment)->getJson('/api/v1/finance/bills?status=outstanding')->assertOk()->assertJsonCount(2, 'data');
    asFinance($this->cashier, Role::AdminPayment)->getJson('/api/v1/finance/bills/grid')->assertOk()->assertJsonPath('data.mode', 'all')->assertJsonCount(1, 'data.columns')->assertJsonCount(2, 'data.rows')->assertJsonPath('data.rows.0.left', 2_000_000);
    asFinance($collector, Role::PaymentCollector)->getJson('/api/v1/finance/bills')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nim', $this->aisyah->nim);
    asFinance($collector, Role::PaymentCollector)->getJson('/api/v1/finance/bills/'.financeBillOf($this->ahmad)->id)->assertForbidden();

    $bill = financeBillOf($this->ahmad);
    asFinance($this->cashier, Role::AdminPayment)->getJson("/api/v1/finance/bills/{$bill->id}")->assertOk()
        ->assertJsonPath('data.allowance.open', 2_000_000)->assertJsonPath('data.can.void', true)->assertJsonPath('data.can.change_installments', false);
    asFinance($this->cashier, Role::AdminPayment)->putJson("/api/v1/finance/bills/{$bill->id}/installments", ['max_installments' => 5, 'reason' => 'x'])->assertForbidden();
    asFinance($this->superadmin, Role::Superadmin)->putJson("/api/v1/finance/bills/{$bill->id}/installments", ['max_installments' => 5, 'reason' => 'Keringanan'])->assertNoContent();
    asFinance($this->cashier, Role::AdminPayment)->postJson("/api/v1/finance/bills/{$bill->id}/void", ['reason' => 'Salah tarif'])->assertNoContent();
    expect($bill->fresh()->status->value)->toBe('void');
});

test('cash: recorded, handed over and counted by an Admin; the finance office does not count its own', function () {
    financeBills();
    $bill = financeBillOf($this->ahmad);

    $payment = asFinance($this->cashier, Role::AdminPayment)->postJson("/api/v1/finance/bills/{$bill->id}/payments", ['amount' => 500_000, 'method' => 'cash', 'paid_on' => today()->toDateString()])
        ->assertCreated()->json('data.payment_id');
    asFinance($this->cashier, Role::AdminPayment)->postJson("/api/v1/finance/bills/{$bill->id}/payments", ['amount' => 100_000, 'method' => 'cash', 'paid_on' => today()->toDateString()])
        ->assertUnprocessable();
    asFinance($this->cashier, Role::AdminPayment)->getJson('/api/v1/finance/my-cash')->assertOk()->assertJsonPath('data.cash.0.id', $payment);
    $handover = asFinance($this->cashier, Role::AdminPayment)->postJson('/api/v1/finance/handovers', ['payment_ids' => [$payment]])->assertCreated()->json('data.id');

    asFinance($this->cashier, Role::AdminPayment)->getJson('/api/v1/finance/to-verify')->assertOk()->assertJsonPath('data.handovers', []);
    asFinance($this->admin, Role::Admin)->getJson('/api/v1/finance/to-verify')->assertOk()->assertJsonPath('data.handovers.0.id', $handover);
    asFinance($this->admin, Role::Admin)->getJson('/api/v1/finance/unverified')->assertOk()->assertJsonPath('data.payments.0.stage', 'handover_waiting');
    asFinance($this->admin, Role::Admin)->postJson("/api/v1/finance/handovers/{$handover}/complete")->assertNoContent();

    expect(Payment::query()->findOrFail($payment)->status->value)->toBe('verified')->and($bill->fresh()->paid_amount)->toBe(500_000);
});

test('transfers from mahasantri are checked by the finance office; a rejection carries the reason', function () {
    financeBills();
    $record = app(RecordPayment::class);
    $first = $record->handle(financeBillOf($this->ahmad), $this->ahmad->user, ['amount' => 500_000, 'method' => 'transfer', 'paid_on' => today()->toDateString(), 'reference' => 'BSI-1'], PaymentSource::Payer);
    $second = $record->handle(financeBillOf($this->aisyah), $this->aisyah->user, ['amount' => 500_000, 'method' => 'transfer', 'paid_on' => today()->toDateString(), 'reference' => 'BSI-2'], PaymentSource::Payer);

    asFinance($this->cashier, Role::AdminPayment)->getJson('/api/v1/finance/to-verify')->assertOk()->assertJsonCount(2, 'data.transfers');
    asFinance($this->cashier, Role::AdminPayment)->postJson("/api/v1/finance/payments/{$first->id}/review", ['verify' => true])->assertNoContent();
    asFinance($this->cashier, Role::AdminPayment)->postJson("/api/v1/finance/payments/{$second->id}/review", ['verify' => false])->assertUnprocessable();
    asFinance($this->cashier, Role::AdminPayment)->postJson("/api/v1/finance/payments/{$second->id}/review", ['verify' => false, 'reason' => 'Tidak ada di mutasi'])->assertNoContent();

    expect($first->fresh()->status->value)->toBe('verified')->and($second->fresh()->status_note)->toBe('Tidak ada di mutasi');
    asFinance($this->superadmin, Role::Superadmin)->postJson("/api/v1/finance/payments/{$first->id}/void", ['reason' => 'Dobel'])->assertNoContent();
});

test('cash book: accounts, an expense with a receipt, a deposit, the ledger, the report and closing', function () {
    $cash = asFinance($this->cashier, Role::AdminPayment)->postJson('/api/v1/finance/cash/accounts', ['name' => 'Kas', 'type' => 'cash', 'opening_balance' => 1_000_000, 'opening_date' => today()->startOfMonth()->toDateString()])->assertOk()->json('data.id');
    $bank = asFinance($this->cashier, Role::AdminPayment)->postJson('/api/v1/finance/cash/accounts', ['name' => 'BSI', 'type' => 'bank', 'opening_balance' => 0, 'opening_date' => today()->startOfMonth()->toDateString()])->assertOk()->json('data.id');
    asFinance($this->cashier, Role::AdminPayment)->putJson('/api/v1/finance/cash/defaults', ['cash_account_id' => $cash, 'bank_account_id' => $bank])->assertNoContent();
    $category = asFinance($this->cashier, Role::AdminPayment)->postJson('/api/v1/finance/cash/categories', ['kind' => 'expense', 'name' => 'ATK'])->assertOk()->json('data.id');

    $entry = asFinance($this->cashier, Role::AdminPayment)->post('/api/v1/finance/cash/entries', [
        'type' => 'expense', 'cash_account_id' => $cash, 'entry_date' => today()->toDateString(), 'amount' => 50_000, 'cash_category_id' => $category,
        'description' => 'Kertas HVS', 'proof' => UploadedFile::fake()->image('nota.jpg'),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    asFinance($this->cashier, Role::AdminPayment)->postJson('/api/v1/finance/cash/transfers', ['from_account_id' => $cash, 'to_account_id' => $bank, 'entry_date' => today()->toDateString(), 'amount' => 200_000, 'description' => 'Setor ke bank'])->assertCreated();

    asFinance($this->cashier, Role::AdminPayment)->getJson('/api/v1/finance/cash/accounts')->assertOk()
        ->assertJsonPath('data.accounts.0.balance', 750_000)->assertJsonPath('data.default_bank_account_id', $bank);
    $ledger = asFinance($this->cashier, Role::AdminPayment)->getJson("/api/v1/finance/cash/accounts/{$cash}/ledger")->assertOk()
        ->assertJsonPath('data.start', 1_000_000)->assertJsonPath('data.end', 750_000)->json('data.rows');
    expect(collect($ledger)->firstWhere('entry_id', $entry)['proof_url'])->toBeString();

    $report = asFinance($this->cashier, Role::AdminPayment)->getJson('/api/v1/finance/cash/summary')->assertOk()->assertJsonPath('data.total_out', 50_000)->json('data.report_url');
    app('auth')->forgetGuards();
    $this->get($report)->assertOk()->assertHeader('content-type', 'application/pdf');

    asFinance($this->cashier, Role::AdminPayment)->postJson("/api/v1/finance/cash/entries/{$entry}/void", ['reason' => 'Salah input'])->assertNoContent();
    asFinance($this->cashier, Role::AdminPayment)->postJson('/api/v1/finance/cash/close', ['month' => today()->subMonth()->format('Y-m')])->assertOk();
    asFinance($this->cashier, Role::AdminPayment)->getJson('/api/v1/finance/overview')->assertOk()->assertJsonPath('data.semester_id', $this->semester->id);
});

test('payments per fee: the bill lists its fees, a payment names them, a fee gets its own limit, the grid filters by fee', function () {
    $monthly = FeeType::factory()->monthly()->create(['name' => 'Syahriah', 'months_per_semester' => 5, 'max_installments' => 2]);
    FeeRate::create(['fee_type_id' => $monthly->id, 'academic_year_id' => $this->semester->academic_year_id, 'amount' => 200_000]);
    financeBills();
    $bill = financeBillOf($this->ahmad);
    $lines = $bill->lines()->get()->keyBy('fee_type_id');
    $main = $lines[$this->fee->id]->id;
    $syahriah = $lines[$monthly->id]->id;

    asFinance($this->cashier, Role::AdminPayment)->getJson("/api/v1/finance/bills/{$bill->id}")->assertOk()
        ->assertJsonCount(2, 'data.fees')
        ->assertJsonPath('data.fees.1.id', $syahriah)
        ->assertJsonPath('data.fees.1.months', 5)
        ->assertJsonPath('data.fees.1.instalments_max', 2)
        ->assertJsonPath('data.fees.1.left', 1_000_000);

    // A per-fee error is reported under that fee.
    $pay = fn (array $allocations) => asFinance($this->cashier, Role::AdminPayment)->postJson("/api/v1/finance/bills/{$bill->id}/payments", ['allocations' => $allocations, 'method' => 'cash', 'paid_on' => today()->toDateString()]);
    $pay([$syahriah => 100_000])->assertUnprocessable()->assertJsonValidationErrors("allocations.{$syahriah}");
    $pay([$main => 500_000, $syahriah => 400_000])->assertCreated();

    asFinance($this->cashier, Role::AdminPayment)->getJson("/api/v1/finance/bills/{$bill->id}")->assertOk()
        ->assertJsonPath('data.payments.0.amount', 900_000)
        ->assertJsonPath('data.payments.0.fees.1.amount', 400_000)
        ->assertJsonPath('data.fees.1.instalments_used', 1);

    // The second (last) Syahriah payment must settle it, unless the Superadmin allows one more.
    $mainLimit = $bill->lines()->whereKey($main)->value('max_installments');
    $pay([$syahriah => 200_000])->assertUnprocessable();
    asFinance($this->superadmin, Role::Superadmin)->putJson("/api/v1/finance/bills/{$bill->id}/installments", ['max_installments' => 3, 'reason' => 'Keringanan', 'fee_id' => $syahriah])->assertNoContent();
    $pay([$syahriah => 200_000])->assertCreated();
    expect($bill->lines()->whereKey($main)->value('max_installments'))->toBe($mainLimit);

    asFinance($this->cashier, Role::AdminPayment)->getJson("/api/v1/finance/bills/grid?fee={$monthly->id}&search={$this->ahmad->nim}")->assertOk()
        ->assertJsonPath('data.mode', 'monthly')
        ->assertJsonCount(5, 'data.columns')
        ->assertJsonCount(2, 'data.fee_types')
        ->assertJsonCount(1, 'data.rows')
        ->assertJsonPath('data.rows.0.pending', 600_000)
        ->assertJsonPath('data.rows.0.cells', ['pending', 'pending', 'pending', 'open', 'open']);
});
