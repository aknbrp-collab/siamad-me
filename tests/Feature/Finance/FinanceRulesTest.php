<?php

use App\Actions\Enrollment\ManageClassEnrollments;
use App\Actions\Finance\AdjustBill;
use App\Actions\Finance\GenerateBills;
use App\Actions\Finance\ManageScholarshipAwards;
use App\Actions\Finance\RecordPayment;
use App\Actions\Finance\ReviewPayments;
use App\Enums\BillStatus;
use App\Enums\HandoverStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Enums\ScholarshipType;
use App\Models\Bill;
use App\Models\ClassGroup;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\Scholarship;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->semester = Semester::factory()->active()->create();
    $this->classGroup = ClassGroup::factory()->create();
    $this->spp = FeeType::factory()->create(['name' => 'SPP']);
    FeeRate::create(['fee_type_id' => $this->spp->id, 'academic_year_id' => $this->semester->academic_year_id, 'amount' => 1_500_000]);

    $this->student = Student::factory()->create(['entry_year' => 2026]);
    app(ManageClassEnrollments::class)->enroll($this->semester, $this->classGroup, [$this->student->id]);

    $this->cashier = User::factory()->withRole(Role::AdminPayment)->create();
    $this->admin = User::factory()->withRole(Role::Admin)->create();
});

function billFor(Student $student): Bill
{
    return Bill::query()->where('student_id', $student->id)->where('status', '!=', 'void')->sole();
}

function pay(Bill $bill, User $by, int $amount, string $method = 'cash'): Payment
{
    return app(RecordPayment::class)->handle($bill, $by, [
        'amount' => $amount, 'method' => $method, 'paid_on' => now()->toDateString(),
        'reference' => $method === 'transfer' ? 'TRX-1' : null,
    ]);
}

test('the most specific fee rate wins and monthly fees are billed six times', function () {
    $yearId = $this->semester->academic_year_id;
    FeeRate::create(['fee_type_id' => $this->spp->id, 'academic_year_id' => $yearId, 'class_group_id' => $this->classGroup->id, 'amount' => 1_200_000]);
    FeeRate::create(['fee_type_id' => $this->spp->id, 'academic_year_id' => $yearId, 'entry_year' => 2026, 'amount' => 1_300_000]);
    $dorm = FeeType::factory()->monthly()->create(['name' => 'Asrama']);
    FeeRate::create(['fee_type_id' => $dorm->id, 'academic_year_id' => $yearId, 'amount' => 100_000]);

    expect(app(GenerateBills::class)->handle($this->semester, '2026-08-31'))->toBe(1);

    $bill = billFor($this->student)->load('lines');
    expect($bill->lines->pluck('amount', 'description')->all())->toBe(['SPP' => 1_200_000, 'Asrama (6 months)' => 600_000])
        ->and($bill->net_amount)->toBe(1_800_000)
        ->and($bill->status)->toBe(BillStatus::Unpaid)
        ->and($bill->max_installments)->toBe(3)
        ->and($bill->number)->toMatch('#^INV/\d{4}/000001$#');
});

test('generating again does not duplicate bills, and one-time fees are billed once', function () {
    $registration = FeeType::factory()->once()->create(['name' => 'Daftar ulang']);
    FeeRate::create(['fee_type_id' => $registration->id, 'academic_year_id' => $this->semester->academic_year_id, 'amount' => 500_000]);

    app(GenerateBills::class)->handle($this->semester);
    expect(app(GenerateBills::class)->handle($this->semester))->toBe(0)
        ->and(billFor($this->student)->net_amount)->toBe(2_000_000);

    $even = Semester::factory()->even()->create(['academic_year_id' => $this->semester->academic_year_id]);
    app(ManageClassEnrollments::class)->enroll($even, $this->classGroup, [$this->student->id]);
    app(GenerateBills::class)->handle($even);

    expect(Bill::query()->where('semester_id', $even->id)->sole()->net_amount)->toBe(1_500_000);
});

test('scholarships discount the bill and respect their quota', function () {
    $scholarship = Scholarship::create([
        'academic_year_id' => $this->semester->academic_year_id, 'name' => 'Beasiswa Yatim', 'funding_source' => 'Yayasan',
        'type' => ScholarshipType::Percent, 'value' => 50, 'quota' => 1,
    ]);
    $awards = app(ManageScholarshipAwards::class);

    $awards->award($scholarship, $this->student, $this->semester, $this->cashier);
    app(GenerateBills::class)->handle($this->semester);

    expect(billFor($this->student))->discount_amount->toBe(750_000)->net_amount->toBe(750_000);

    $other = Student::factory()->create();
    expect(fn () => $awards->award($scholarship, $other, $this->semester, $this->cashier))->toThrow(ValidationException::class);
});

test('a scholarship awarded after billing is added to the bill, and revoking removes it', function () {
    app(GenerateBills::class)->handle($this->semester);
    $full = Scholarship::create([
        'academic_year_id' => $this->semester->academic_year_id, 'name' => 'Beasiswa Penuh', 'funding_source' => 'Pemerintah',
        'type' => ScholarshipType::Full, 'value' => 0,
    ]);

    $award = app(ManageScholarshipAwards::class)->award($full, $this->student, $this->semester, $this->cashier);
    expect(billFor($this->student))->net_amount->toBe(0)->status->toBe(BillStatus::Paid);

    app(ManageScholarshipAwards::class)->revoke($award);
    expect(billFor($this->student))->net_amount->toBe(1_500_000)->status->toBe(BillStatus::Unpaid);
});

test('instalment rules: minimum amount, maximum count, last one settles', function () {
    app(GenerateBills::class)->handle($this->semester);
    $bill = billFor($this->student);

    expect(fn () => pay($bill, $this->cashier, 100_000))->toThrow(ValidationException::class); // below Rp 200.000
    expect(fn () => pay($bill, $this->cashier, 1_600_000))->toThrow(ValidationException::class); // more than owed

    pay($bill, $this->cashier, 500_000);
    pay($bill, $this->cashier, 500_000);

    // Third and last allowed instalment must settle the remaining Rp 500.000.
    expect(fn () => pay($bill, $this->cashier, 300_000))->toThrow(ValidationException::class);
    pay($bill, $this->cashier, 500_000);

    expect(fn () => pay($bill, $this->cashier, 200_000))->toThrow(ValidationException::class);
});

test('a payment below the minimum is fine when it settles the rest', function () {
    app(GenerateBills::class)->handle($this->semester);
    $bill = billFor($this->student);

    pay($bill, $this->cashier, 1_400_000);
    pay($bill, $this->cashier, 100_000);

    expect($bill->payments()->count())->toBe(2);
});

test('unverified payments do not count until an admin verifies the handover', function () {
    app(GenerateBills::class)->handle($this->semester);
    $bill = billFor($this->student);
    $first = pay($bill, $this->cashier, 1_000_000);
    $second = pay($bill, $this->cashier, 500_000);

    expect($bill->fresh())->paid_amount->toBe(0)->status->toBe(BillStatus::Unpaid);

    $review = app(ReviewPayments::class);
    $handover = $review->handOver($this->cashier, [$first->id, $second->id]);
    expect($handover->total_amount)->toBe(1_500_000);

    // The cashier cannot receive their own handover.
    expect(fn () => $review->completeHandover($handover, $this->cashier))->toThrow(ValidationException::class);

    $review->completeHandover($handover, $this->admin, [$second->id => 'Uang kurang']);

    expect($handover->fresh()->status)->toBe(HandoverStatus::PartiallyRejected)
        ->and($first->fresh())->status->toBe(PaymentStatus::Verified)->receipt_number->toMatch('#^KW/\d{4}/000001$#')
        ->and($second->fresh())->status->toBe(PaymentStatus::Rejected)->status_note->toBe('Uang kurang')
        ->and($bill->fresh())->paid_amount->toBe(1_000_000)->status->toBe(BillStatus::Partial);

    // The rejected payment frees its instalment slot and amount.
    pay($bill, $this->cashier, 500_000);
});

test('transfers are verified individually, never by the recorder', function () {
    app(GenerateBills::class)->handle($this->semester);
    $payment = pay(billFor($this->student), $this->cashier, 1_500_000, 'transfer');
    $review = app(ReviewPayments::class);

    expect(fn () => $review->verifyTransfer($payment, $this->cashier))->toThrow(ValidationException::class);

    $review->verifyTransfer($payment, $this->admin);

    expect(billFor($this->student)->status)->toBe(BillStatus::Paid);
});

test('cash payments cannot be verified outside a handover', function () {
    app(GenerateBills::class)->handle($this->semester);
    $payment = pay(billFor($this->student), $this->cashier, 1_500_000);

    expect(fn () => app(ReviewPayments::class)->verifyTransfer($payment, $this->admin))->toThrow(ValidationException::class);
});

test('voiding a verified payment reopens the bill', function () {
    app(GenerateBills::class)->handle($this->semester);
    $payment = pay(billFor($this->student), $this->cashier, 1_500_000, 'transfer');
    app(ReviewPayments::class)->verifyTransfer($payment, $this->admin);

    app(ReviewPayments::class)->void($payment, 'Transfer dibatalkan bank');

    expect($payment->fresh()->status)->toBe(PaymentStatus::Void)
        ->and(billFor($this->student))->status->toBe(BillStatus::Unpaid)->paid_amount->toBe(0);
});

test('bills with payments cannot be voided; empty ones can and are regenerated', function () {
    app(GenerateBills::class)->handle($this->semester);
    $bill = billFor($this->student);

    app(AdjustBill::class)->void($bill, 'Tarif salah');
    expect($bill->fresh()->status)->toBe(BillStatus::Void)
        ->and(app(GenerateBills::class)->handle($this->semester))->toBe(1);

    $new = billFor($this->student);
    pay($new, $this->cashier, 500_000);
    expect(fn () => app(AdjustBill::class)->void($new, 'x'))->toThrow(ValidationException::class);
});

test('the database refuses two live bills for one student and semester', function () {
    app(GenerateBills::class)->handle($this->semester);

    expect(fn () => Bill::create([
        'student_id' => $this->student->id, 'semester_id' => $this->semester->id, 'number' => 'X',
        'gross_amount' => 1, 'net_amount' => 1, 'max_installments' => 3,
    ]))->toThrow(QueryException::class);
});

test('bills cannot be generated for a closed semester', function () {
    $this->semester->update(['status' => 'closed']);

    expect(fn () => app(GenerateBills::class)->handle($this->semester))->toThrow(ValidationException::class);
});
