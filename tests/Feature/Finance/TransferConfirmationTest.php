<?php

use App\Actions\Enrollment\ManageClassEnrollments;
use App\Actions\Finance\GenerateBills;
use App\Actions\Finance\RecordPayment;
use App\Actions\Finance\ReviewPayments;
use App\Enums\BillStatus;
use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Bill;
use App\Models\ClassGroup;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\Payment;
use App\Models\Semester;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    $semester = Semester::factory()->active()->create();
    $class = ClassGroup::factory()->create();
    $this->student = Student::factory()->create();
    $this->other = Student::factory()->create();
    app(ManageClassEnrollments::class)->enroll($semester, $class, [$this->student->id, $this->other->id]);

    $fee = FeeType::factory()->create();
    FeeRate::create(['fee_type_id' => $fee->id, 'academic_year_id' => $semester->academic_year_id, 'amount' => 3_000_000]);
    app(GenerateBills::class)->handle($semester);

    $this->bill = Bill::query()->where('student_id', $this->student->id)->sole();
    $this->otherBill = Bill::query()->where('student_id', $this->other->id)->sole();

    $this->cashier = User::factory()->withRole(Role::AdminPayment)->create();
    $this->admin = User::factory()->withRole(Role::Admin)->create();
});

function sendTransfer(Bill $bill, int $amount = 1_000_000)
{
    return Livewire::test('pages::mahasantri.bills')
        ->call('openTransfer', $bill->id)
        ->set('transferAmount', $amount)
        ->set('transferReference', 'BSI a.n. Abdullah')
        ->set('transferProof', UploadedFile::fake()->image('bukti.jpg'))
        ->call('submitTransfer');
}

test('a mahasantri confirms a transfer with the bank receipt', function () {
    Setting::set('payment.bank_accounts', 'BSI 7123456789 a.n. Yayasan');
    $this->actingAs($this->student->user)->get(route('mahasantri.bills'))
        ->assertOk()
        ->assertSeeHtml('data-test="confirm-transfer-'.$this->bill->id.'"');

    Livewire::test('pages::mahasantri.bills')
        ->call('openTransfer', $this->bill->id)
        ->assertSee('BSI 7123456789 a.n. Yayasan')
        ->set('transferAmount', 1_000_000)
        ->call('submitTransfer')
        ->assertHasErrors(['reference', 'proof']);

    sendTransfer($this->bill)->assertHasNoErrors();

    $payment = Payment::query()->sole();
    expect($payment)
        ->source->toBe(PaymentSource::Payer)
        ->status->toBe(PaymentStatus::Unverified)
        ->recorded_by->toBe($this->student->user_id)
        ->amount->toBe(1_000_000)
        ->and($this->bill->fresh()->status)->toBe(BillStatus::Unpaid);

    Storage::disk('local')->assertExists($payment->proof_path);
});

test('the instalment rules apply to confirmations too', function () {
    $this->actingAs($this->student->user)->get(route('mahasantri.bills'));

    sendTransfer($this->bill, 100_000)->assertHasErrors('amount');
    sendTransfer($this->bill, 3_500_000)->assertHasErrors('amount');

    expect(Payment::query()->count())->toBe(0);
});

test('only the mahasantri and their own wali can confirm for a bill', function () {
    $guardian = Guardian::factory()->create();
    $guardian->students()->attach($this->student);

    $this->actingAs($guardian->user)->get(route('wali.bills'))->assertOk()->assertSee(__('Confirm transfer'));
    Livewire::test('pages::wali.bills')
        ->call('openTransfer', $this->bill->id)
        ->set('transferAmount', 1_000_000)
        ->set('transferReference', 'BRI a.n. Bapak')
        ->set('transferProof', UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf'))
        ->call('submitTransfer')
        ->assertHasNoErrors();

    expect(Payment::query()->sole()->recorded_by)->toBe($guardian->user_id);

    // Another family's bill: refused for the wali and for a mahasantri alike.
    Livewire::test('pages::wali.bills')->call('openTransfer', $this->otherBill->id)->assertForbidden();

    $this->actingAs($this->student->user)->get(route('mahasantri.bills'));
    Livewire::test('pages::mahasantri.bills')->call('openTransfer', $this->otherBill->id)->assertForbidden();
});

test('admin keuangan verifies a confirmed transfer and an official receipt is issued', function () {
    $this->actingAs($this->student->user)->get(route('mahasantri.bills'));
    sendTransfer($this->bill)->assertHasNoErrors();
    $payment = Payment::query()->sole();

    $this->actingAs($this->cashier)->get(route('finance.transfers'))
        ->assertOk()
        ->assertSee($this->student->user->name)
        ->assertSee('BSI a.n. Abdullah');

    Livewire::test('pages::finance.transfers')->call('verify', $payment->id)->assertHasNoErrors();

    expect($payment->fresh())
        ->status->toBe(PaymentStatus::Verified)
        ->verified_by->toBe($this->cashier->id)
        ->receipt_number->not->toBeNull()
        ->and($this->bill->fresh()->status)->toBe(BillStatus::Partial);
});

test('a rejected confirmation shows the reason to the payer, who can send again', function () {
    $this->actingAs($this->student->user)->get(route('mahasantri.bills'));
    sendTransfer($this->bill)->assertHasNoErrors();
    $payment = Payment::query()->sole();

    $this->actingAs($this->cashier)->get(route('finance.transfers'));
    Livewire::test('pages::finance.transfers')
        ->call('startReject', $payment->id)
        ->call('reject')
        ->assertHasErrors('rejectionReason')
        ->set('rejectionReason', 'Tidak ada di rekening koran')
        ->call('reject')
        ->assertHasNoErrors();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Rejected);

    $this->actingAs($this->student->user)->get(route('mahasantri.bills'))
        ->assertSee('Tidak ada di rekening koran')
        ->assertSeeHtml('data-test="confirm-transfer-'.$this->bill->id.'"');
});

test('admin keuangan cannot verify transfers the finance office recorded itself', function () {
    $staffTransfer = app(RecordPayment::class)->handle($this->bill, $this->cashier, [
        'amount' => 1_000_000, 'method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => 'BSI 01',
    ]);
    $otherCashier = User::factory()->withRole(Role::AdminPayment)->create();

    expect(fn () => app(ReviewPayments::class)->verifyTransfer($staffTransfer, $otherCashier))
        ->toThrow(ValidationException::class);

    // The finance queue only lists transfers from mahasantri / wali.
    $this->actingAs($otherCashier)->get(route('finance.transfers'))->assertOk()->assertDontSee('BSI 01');

    app(ReviewPayments::class)->verifyTransfer($staffTransfer, $this->admin);
    expect($staffTransfer->fresh()->status)->toBe(PaymentStatus::Verified);
});

test('the admin can verify confirmations too, and sees where they came from', function () {
    $this->actingAs($this->student->user)->get(route('mahasantri.bills'));
    sendTransfer($this->bill)->assertHasNoErrors();

    $this->actingAs($this->admin)->get(route('admin.payments', ['tab' => 'transfers']))
        ->assertOk()
        ->assertSee(__('Sent by mahasantri / wali'));

    app(ReviewPayments::class)->verifyTransfer(Payment::query()->sole(), $this->admin);
    expect(Payment::query()->sole()->status)->toBe(PaymentStatus::Verified);
});

test('the payer can open their own receipt upload; other mahasantri cannot', function () {
    $this->actingAs($this->student->user)->get(route('mahasantri.bills'));
    sendTransfer($this->bill)->assertHasNoErrors();
    $payment = Payment::query()->sole();

    $this->get(route('files.show', ['payment-proof', $payment->id]))->assertOk();
    $this->actingAs($this->other->user)->get(route('files.show', ['payment-proof', $payment->id]))->assertForbidden();
});

test('transfer confirmations are in the finance menu and closed to other roles', function () {
    expect(collect(Role::AdminPayment->navigation())->flatMap(fn ($group) => $group['items'])->pluck('route'))
        ->toContain('finance.transfers');

    $this->actingAs($this->student->user)->get(route('finance.transfers'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('finance.transfers'))->assertForbidden();
});
