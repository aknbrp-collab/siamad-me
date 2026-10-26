<?php

use App\Actions\Enrollment\ManageClassEnrollments;
use App\Actions\Finance\GenerateBills;
use App\Enums\BillStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Bill;
use App\Models\ClassGroup;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\Payment;
use App\Models\PaymentHandover;
use App\Models\Scholarship;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Support\ActiveRole;
use App\Support\Money;
use Livewire\Livewire;

beforeEach(function () {
    $this->semester = Semester::factory()->active()->create();
    $this->classGroup = ClassGroup::factory()->create(['name' => 'Marhalah 1 A']);
    $this->student = Student::factory()->create();
    app(ManageClassEnrollments::class)->enroll($this->semester, $this->classGroup, [$this->student->id]);

    $this->cashier = User::factory()->withRole(Role::AdminPayment)->create(['name' => 'Kasir']);
    $this->admin = User::factory()->withRole(Role::Admin)->create(['name' => 'Bendahara']);
});

function actAs(User $user): void
{
    test()->actingAs($user);
    ActiveRole::set($user->roleList()[0]);
}

test('admin payment sets up fees and generates bills from the pages', function () {
    actAs($this->cashier);

    $this->get(route('finance.fees'))->assertOk();

    Livewire::test('pages::finance.fees')
        ->call('createFeeType')
        ->set('feeName', 'SPP')
        ->call('saveFeeType')
        ->assertHasNoErrors()
        ->call('createRate')
        ->set('rateAmount', 1_500_000)
        ->call('saveRate')
        ->assertHasNoErrors()
        ->assertSee('Rp 1.500.000');

    Livewire::test('pages::finance.fees')
        ->call('createRate')
        ->set('rateAmount', 999)
        ->call('saveRate')
        ->assertHasErrors('rateAmount');

    $this->get(route('finance.bills.index'))->assertOk();

    Livewire::test('pages::finance.bills.index')
        ->call('openGenerate')
        ->assertSet('preview.students', 1)
        ->assertSet('preview.net', 1_500_000)
        ->call('generate')
        ->assertHasNoErrors()
        ->assertSee($this->student->user->name);

    expect(Bill::query()->sole())->net_amount->toBe(1_500_000)->status->toBe(BillStatus::Unpaid);
});

test('the full payment roadmap: record, hand over, verify, receipt', function () {
    $fee = FeeType::factory()->create();
    FeeRate::create(['fee_type_id' => $fee->id, 'academic_year_id' => $this->semester->academic_year_id, 'amount' => 1_000_000]);
    app(GenerateBills::class)->handle($this->semester);
    $bill = Bill::query()->sole();

    // 1. Admin Payment records cash (unverified).
    actAs($this->cashier);
    $this->get(route('finance.bills.show', $bill))->assertOk()->assertSee($bill->number);

    Livewire::test('pages::finance.bills.show', ['bill' => $bill])
        ->set('amount', 100_000)
        ->call('recordPayment')
        ->assertHasErrors('amount')
        ->set('amount', 600_000)
        ->call('recordPayment')
        ->assertHasNoErrors();

    $payment = Payment::query()->sole();
    expect($payment->status)->toBe(PaymentStatus::Unverified)
        ->and($bill->fresh()->paid_amount)->toBe(0);

    // A provisional slip is available before verification.
    $this->get(route('receipts.show', $payment))->assertOk()->assertHeader('content-type', 'application/pdf');

    // 2. Hand the cash over.
    $this->get(route('finance.handovers'))->assertOk()->assertSee('Rp 600.000');
    Livewire::test('pages::finance.handovers')
        ->call('selectAll')
        ->call('handOver')
        ->assertHasNoErrors();

    $handover = PaymentHandover::query()->sole();

    // 3. Admin counts and verifies.
    actAs($this->admin);
    $this->get(route('admin.payments'))->assertOk()->assertSee($handover->number);
    Livewire::test('pages::admin.payments')
        ->call('openHandover', $handover->id)
        ->assertSet('acceptedTotal', 600_000)
        ->call('completeHandover')
        ->assertHasNoErrors();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Verified)
        ->and($bill->fresh())->paid_amount->toBe(600_000)->status->toBe(BillStatus::Partial);

    // 4. The mahasantri sees the bill and opens the official receipt.
    actAs($this->student->user);
    $this->get(route('mahasantri.bills'))->assertOk()->assertSee('Rp 400.000')->assertSee(__('Receipt'));
    $this->get(route('receipts.show', $payment))->assertOk();
});

test('an admin rejects a bank transfer with a reason', function () {
    $fee = FeeType::factory()->create();
    FeeRate::create(['fee_type_id' => $fee->id, 'academic_year_id' => $this->semester->academic_year_id, 'amount' => 500_000]);
    app(GenerateBills::class)->handle($this->semester);
    $bill = Bill::query()->sole();

    actAs($this->cashier);
    Livewire::test('pages::finance.bills.show', ['bill' => $bill])
        ->set('method', 'transfer')
        ->set('amount', 500_000)
        ->call('recordPayment')
        ->assertHasErrors('reference')
        ->set('reference', 'BSI 123')
        ->call('recordPayment')
        ->assertHasNoErrors();

    actAs($this->admin);
    Livewire::test('pages::admin.payments')
        ->set('tab', 'transfers')
        ->assertSee('BSI 123')
        ->call('startRejectTransfer', Payment::query()->sole()->id)
        ->set('rejectionReason', 'Tidak ada di rekening koran')
        ->call('rejectTransfer')
        ->assertHasNoErrors();

    expect(Payment::query()->sole()->status)->toBe(PaymentStatus::Rejected);
});

test('wali santri see only their own children\'s bills', function () {
    $fee = FeeType::factory()->create();
    FeeRate::create(['fee_type_id' => $fee->id, 'academic_year_id' => $this->semester->academic_year_id, 'amount' => 750_000]);
    $other = Student::factory()->create();
    app(ManageClassEnrollments::class)->enroll($this->semester, $this->classGroup, [$other->id]);
    app(GenerateBills::class)->handle($this->semester);

    $guardian = Guardian::factory()->create();
    $guardian->students()->attach($this->student);

    actAs($guardian->user);
    $this->get(route('wali.bills'))
        ->assertOk()
        ->assertSee($this->student->user->name)
        ->assertDontSee($other->user->name);

    $otherPayment = Bill::query()->where('student_id', $other->id)->sole()->payments()->create([
        'amount' => 750_000, 'method' => 'cash', 'paid_on' => now(), 'recorded_by' => $this->cashier->id, 'status' => PaymentStatus::Unverified,
    ]);
    $this->get(route('receipts.show', $otherPayment))->assertForbidden();
});

test('scholarships are managed and awarded from the page', function () {
    actAs($this->cashier);
    $this->get(route('finance.scholarships'))->assertOk();

    $component = Livewire::test('pages::finance.scholarships')
        ->call('create')
        ->set('name', 'Beasiswa Tahfidz')
        ->set('fundingSource', 'Donatur')
        ->set('type', 'fixed')
        ->set('value', 250_000)
        ->call('save')
        ->assertHasNoErrors();

    $scholarship = Scholarship::query()->sole();

    $component->set('studentSearch', $this->student->nim)
        ->call('award', $this->student->id)
        ->assertHasNoErrors()
        ->assertSee($this->student->user->name);

    expect($scholarship->awards()->count())->toBe(1);
});

test('finance pages are closed to other roles and verification to admin payment', function () {
    actAs($this->student->user);
    $this->get(route('finance.bills.index'))->assertForbidden();

    actAs($this->admin);
    $this->get(route('finance.fees'))->assertForbidden();

    actAs($this->cashier);
    $this->get(route('admin.payments'))->assertForbidden();
});

test('the finance dashboard summarises the semester', function () {
    $fee = FeeType::factory()->create();
    FeeRate::create(['fee_type_id' => $fee->id, 'academic_year_id' => $this->semester->academic_year_id, 'amount' => 1_200_000]);
    app(GenerateBills::class)->handle($this->semester);

    actAs($this->cashier);
    $this->get(route('finance.dashboard'))
        ->assertOk()
        ->assertSee('Rp 1.200.000')
        ->assertSee('Marhalah 1 A');
});

test('amounts in words read correctly', function (int $amount, string $words) {
    expect(Money::inWords($amount))->toBe($words);
})->with([
    [0, 'nol rupiah'],
    [11, 'sebelas rupiah'],
    [115, 'seratus lima belas rupiah'],
    [1_250_000, 'satu juta dua ratus lima puluh ribu rupiah'],
    [1_000, 'seribu rupiah'],
    [21_500_000, 'dua puluh satu juta lima ratus ribu rupiah'],
]);
