<?php

use App\Actions\Enrollment\ManageClassEnrollments;
use App\Actions\Finance\AdjustBill;
use App\Actions\Finance\GenerateBills;
use App\Actions\Finance\RecordPayment;
use App\Actions\Finance\ReviewPayments;
use App\Enums\PaymentStatus;
use App\Enums\PrintDocument;
use App\Enums\Role;
use App\Models\Bill;
use App\Models\ClassGroup;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\Semester;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Support\ActiveRole;
use App\Support\BillFees;
use App\Support\PrintMarks;
use Illuminate\Validation\ValidationException;

/*
 * after-review D55: each fee of a bill is paid on its own, within its own instalment limit.
 */

beforeEach(function () {
    $this->semester = Semester::factory()->active()->create();
    $class = ClassGroup::factory()->create();
    $yearId = $this->semester->academic_year_id;

    $this->spp = FeeType::factory()->create(['name' => 'SPP', 'max_installments' => 1]);
    $this->syahriah = FeeType::factory()->monthly()->create(['name' => 'Syahriah', 'months_per_semester' => 5, 'max_installments' => 3]);
    FeeRate::create(['fee_type_id' => $this->spp->id, 'academic_year_id' => $yearId, 'amount' => 1_000_000]);
    FeeRate::create(['fee_type_id' => $this->syahriah->id, 'academic_year_id' => $yearId, 'amount' => 200_000]);

    $this->student = Student::factory()->create();
    app(ManageClassEnrollments::class)->enroll($this->semester, $class, [$this->student->id]);
    app(GenerateBills::class)->handle($this->semester);
    $this->bill = Bill::query()->where('student_id', $this->student->id)->sole();
    $lines = $this->bill->lines()->get()->keyBy('fee_type_id');
    $this->sppLine = $lines[$this->spp->id];
    $this->syahriahLine = $lines[$this->syahriah->id];

    $this->cashier = User::factory()->withRole(Role::AdminPayment)->create();
    $this->admin = User::factory()->withRole(Role::Admin)->create();
});

function feePay(object $test, array $input): Payment
{
    return app(RecordPayment::class)->handle($test->bill->fresh(), $test->cashier, ['method' => 'cash', 'paid_on' => now()->toDateString(), ...$input]);
}

test('a discount is shared by the fees in proportion, adding up exactly', function () {
    expect(BillFees::shares([1 => 700, 2 => 300], 100))->toBe([1 => 70, 2 => 30])
        ->and(array_sum(BillFees::shares([1 => 1, 2 => 1, 3 => 1], 2)))->toBe(2)
        ->and(BillFees::shares([1 => 500], 0))->toBe([1 => 0]);
});

test('each fee shows what it is due, left and its own limit', function () {
    $fees = BillFees::of($this->bill);

    expect($fees[$this->sppLine->id])->due->toBe(1_000_000)->left->toBe(1_000_000)->used->toBe(0)->max->toBe(1)
        ->and($fees[$this->syahriahLine->id])->due->toBe(1_000_000)->max->toBe(3)
        ->and($this->syahriahLine->months)->toBe(5)
        ->and(BillFees::open($this->bill))->toBe(2_000_000);
});

test('a payment names its fees; each fee keeps its own rules', function () {
    // SPP may be paid once only: in part is refused.
    expect(fn () => feePay($this, ['allocations' => [$this->sppLine->id => 400_000]]))
        ->toThrow(ValidationException::class, __('This is the last allowed payment for :fee; it must settle it (:left).', ['fee' => 'SPP', 'left' => 'Rp 1.000.000']));

    // The total must be the sum of the fees.
    expect(fn () => feePay($this, ['amount' => 999, 'allocations' => [$this->sppLine->id => 1_000_000]]))->toThrow(ValidationException::class);

    $payment = feePay($this, ['allocations' => [$this->sppLine->id => 1_000_000, $this->syahriahLine->id => 400_000]]);
    expect($payment->amount)->toBe(1_400_000)
        ->and($payment->allocations()->pluck('amount', 'bill_line_id')->all())->toBe([$this->sppLine->id => 1_000_000, $this->syahriahLine->id => 400_000]);

    // Syahriah: two more payments at most, and the third must settle it.
    feePay($this, ['allocations' => [$this->syahriahLine->id => 200_000]]);
    expect(fn () => feePay($this, ['allocations' => [$this->syahriahLine->id => 200_000]]))->toThrow(ValidationException::class);
    feePay($this, ['allocations' => [$this->syahriahLine->id => 400_000]]);

    expect(BillFees::open($this->bill))->toBe(0)
        ->and(fn () => feePay($this, ['amount' => 1]))->toThrow(ValidationException::class);
});

test('a payment without fees is spread in the bill order, within the limits', function () {
    $payment = feePay($this, ['amount' => 1_300_000]);

    expect($payment->allocations()->pluck('amount', 'bill_line_id')->all())->toBe([$this->sppLine->id => 1_000_000, $this->syahriahLine->id => 300_000])
        ->and(fn () => feePay($this, ['amount' => 900_000]))->toThrow(ValidationException::class);
});

test('a rejected payment no longer counts toward its fee', function () {
    $payment = feePay($this, ['method' => 'transfer', 'reference' => 'TRX-1', 'allocations' => [$this->sppLine->id => 1_000_000]]);
    expect(BillFees::of($this->bill)[$this->sppLine->id])->pending->toBe(1_000_000)->used->toBe(1);

    app(ReviewPayments::class)->rejectTransfer($payment, $this->admin, 'Tidak masuk ke rekening');

    expect($payment->fresh()->status)->toBe(PaymentStatus::Rejected)
        ->and(BillFees::of($this->bill)[$this->sppLine->id])->pending->toBe(0)->used->toBe(0)->left->toBe(1_000_000);
});

test('the Superadmin can allow one fee more instalments', function () {
    app(AdjustBill::class)->setMaxInstallments($this->bill, 2, 'Keringanan orang tua', $this->sppLine);

    expect(BillFees::of($this->bill)[$this->sppLine->id]['max'])->toBe(2)
        ->and(BillFees::of($this->bill)[$this->syahriahLine->id]['max'])->toBe(3);

    feePay($this, ['allocations' => [$this->sppLine->id => 500_000]]);
    expect(fn () => app(AdjustBill::class)->setMaxInstallments($this->bill, 0, 'x', $this->sppLine))->toThrow(ValidationException::class);
});

test('the receipt lists the fees paid', function () {
    $payment = feePay($this, ['allocations' => [$this->sppLine->id => 1_000_000, $this->syahriahLine->id => 400_000]]);
    $payment->load(['bill.student.user', 'bill.semester.academicYear', 'recorder', 'verifier', 'allocations.line']);

    $html = view('receipts.payment', [
        'payment' => $payment,
        'document' => PrintDocument::Receipt,
        'marks' => PrintMarks::defaults(PrintDocument::Receipt),
        'footer' => null,
    ])->render();

    expect($html)->toContain('Rincian')->toContain('SPP: Rp 1.000.000')->toContain('Syahriah (5 months): Rp 400.000');
});

function feeActAs(User $user): void
{
    test()->actingAs($user);
    ActiveRole::set($user->roleList()[0]);
}

test('the bill page pays the ticked fees, by amount or by months, with the total as Jumlah', function () {
    feeActAs($this->cashier);
    $spp = $this->sppLine->id;
    $syahriah = $this->syahriahLine->id;

    $this->get(route('finance.bills.show', $this->bill))->assertOk()->assertSee('SPP')->assertSee('Syahriah');

    $page = Livewire\Livewire::test('pages::finance.bills.show', ['bill' => $this->bill])
        ->call('recordPayment')
        ->assertHasErrors('allocations')
        // Ticking fills what is left; months set a monthly fee.
        ->set("pay.{$spp}", true)
        ->assertSet("feeAmounts.{$spp}", 1_000_000)
        ->set("pay.{$syahriah}", true)
        ->assertSet("feeAmounts.{$syahriah}", 1_000_000)
        ->assertSet("feeMonths.{$syahriah}", 5)
        ->set("feeMonths.{$syahriah}", 2)
        ->assertSet("feeAmounts.{$syahriah}", 400_000)
        ->assertSee('Rp 1.400.000')
        // SPP's only payment must settle it: the error is under that fee.
        ->set("feeAmounts.{$spp}", 500_000)
        ->call('recordPayment')
        ->assertHasErrors("allocations.{$spp}")
        ->assertHasNoErrors("allocations.{$syahriah}")
        ->set("feeAmounts.{$spp}", 1_000_000)
        ->call('recordPayment')
        ->assertHasNoErrors()
        ->assertSet('pay', []);

    $payment = Payment::query()->sole();
    expect($payment->amount)->toBe(1_400_000)
        ->and($payment->allocations()->pluck('amount', 'bill_line_id')->all())->toBe([$spp => 1_000_000, $syahriah => 400_000]);

    // The payment lists its fees; SPP can take no more.
    $page->assertSee('SPP: Rp 1.000.000')->assertSee('Syahriah (5 months): Rp 400.000')
        ->set("pay.{$spp}", true)
        ->assertSet('pay', []);
});

test('fees ticked together are each filled', function () {
    feeActAs($this->cashier);

    Livewire\Livewire::test('pages::finance.bills.show', ['bill' => $this->bill])
        ->set('pay', [$this->sppLine->id => true, $this->syahriahLine->id => true])
        ->assertSet('feeAmounts', [$this->sppLine->id => 1_000_000, $this->syahriahLine->id => 1_000_000])
        ->set('pay', [$this->syahriahLine->id => true])
        ->assertSet('feeAmounts', [$this->syahriahLine->id => 1_000_000]);
});

test('the Superadmin changes the instalment limit of one fee from the bill page', function () {
    feeActAs(User::factory()->withRole(Role::Superadmin)->create());

    Livewire\Livewire::test('pages::finance.bills.show', ['bill' => $this->bill])
        ->call('openOverride', $this->sppLine->id)
        ->assertSet('maxInstallments', 1)
        ->set('maxInstallments', 2)
        ->set('overrideReason', 'Keringanan')
        ->call('overrideInstallments')
        ->assertHasNoErrors();

    expect($this->sppLine->fresh()->max_installments)->toBe(2)
        ->and($this->syahriahLine->fresh()->max_installments)->toBe(3);

    // Others see no link.
    feeActAs($this->cashier);
    Livewire\Livewire::test('pages::finance.bills.show', ['bill' => $this->bill])->assertDontSeeHtml('data-test="fee-limit"');
});

test('a monthly fee may be paid by whole months below the minimum instalment', function () {
    Setting::set('payment.min_installment_amount', 300_000);
    $line = $this->syahriahLine->id;

    // 200,000 a month: one month is below the minimum but whole.
    feePay($this, ['allocations' => [$line => 200_000]]);

    // Half a month is not.
    expect(fn () => feePay($this, ['allocations' => [$line => 100_000]]))
        ->toThrow(ValidationException::class, __('A payment for :fee must be at least :min, unless it pays whole months (:monthly a month) or settles it (:left).', [
            'fee' => 'Syahriah (5 months)', 'min' => 'Rp 300.000', 'monthly' => 'Rp 200.000', 'left' => 'Rp 800.000',
        ]));

    // A non-monthly fee keeps the minimum; a spread payment follows the same rule.
    app(AdjustBill::class)->setMaxInstallments($this->bill, 3, 'x', $this->sppLine);
    expect(fn () => feePay($this, ['allocations' => [$this->sppLine->id => 200_000]]))->toThrow(ValidationException::class);
    feePay($this, ['allocations' => [$this->sppLine->id => 1_000_000]]);
    $payment = feePay($this, ['amount' => 200_000]);

    expect($payment->allocations()->pluck('amount', 'bill_line_id')->all())->toBe([$line => 200_000])
        ->and(BillFees::of($this->bill)[$line])->pending->toBe(400_000)->left->toBe(600_000);
});
