<?php

use App\Actions\Enrollment\ManageClassEnrollments;
use App\Actions\Finance\AddFeeToBills;
use App\Actions\Finance\GenerateBills;
use App\Actions\Finance\ManageScholarshipAwards;
use App\Actions\Finance\RecordPayment;
use App\Actions\Finance\ReviewPayments;
use App\Enums\BillStatus;
use App\Enums\FeeFrequency;
use App\Enums\Role;
use App\Enums\ScholarshipType;
use App\Models\Bill;
use App\Models\ClassGroup;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\Scholarship;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->semester = Semester::factory()->active()->create();
    $this->classA = ClassGroup::factory()->create();
    $this->classB = ClassGroup::factory()->create();
    $this->paid = Student::factory()->create();
    $this->scholar = Student::factory()->create();
    $this->other = Student::factory()->create();
    app(ManageClassEnrollments::class)->enroll($this->semester, $this->classA, [$this->paid->id, $this->scholar->id]);
    app(ManageClassEnrollments::class)->enroll($this->semester, $this->classB, [$this->other->id]);

    $this->cashier = User::factory()->withRole(Role::AdminPayment)->create();
    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $year = $this->semester->academic_year_id;

    $spp = FeeType::factory()->create(['name' => 'SPP']);
    FeeRate::create(['fee_type_id' => $spp->id, 'academic_year_id' => $year, 'amount' => 1_000_000]);

    $half = Scholarship::create(['academic_year_id' => $year, 'name' => 'Beasiswa Yatim', 'funding_source' => 'Yayasan', 'type' => ScholarshipType::Percent, 'value' => 50, 'quota' => 5]);
    app(ManageScholarshipAwards::class)->award($half, $this->scholar, $this->semester, $this->cashier);

    app(GenerateBills::class)->handle($this->semester);
    $this->bill = fn (Student $student) => Bill::query()->where('student_id', $student->id)->sole();

    // One bill is fully paid before the new fee arrives.
    $payment = app(RecordPayment::class)->handle(($this->bill)($this->paid), $this->cashier, ['amount' => 1_000_000, 'method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => 'X']);
    app(ReviewPayments::class)->verifyTransfer($payment, $this->admin);

    // Added after the bills: Asrama, more for class B.
    $this->asrama = FeeType::factory()->create(['name' => 'Asrama']);
    FeeRate::create(['fee_type_id' => $this->asrama->id, 'academic_year_id' => $year, 'amount' => 200_000]);
    FeeRate::create(['fee_type_id' => $this->asrama->id, 'academic_year_id' => $year, 'class_group_id' => $this->classB->id, 'amount' => 300_000]);
});

test('a fee added after billing goes onto existing bills at the matching rate', function () {
    $wali = Guardian::factory()->create();
    $wali->students()->attach($this->paid->id);

    $preview = app(AddFeeToBills::class)->preview($this->asrama, $this->semester);
    expect($preview['entries'])->toHaveCount(3)
        ->and($preview['entries']->firstWhere('bill.student_id', $this->other->id)['amount'])->toBe(300_000)
        // Half of the larger bill is covered by the 50% scholarship.
        ->and($preview['entries']->firstWhere('bill.student_id', $this->scholar->id)['discount'])->toBe(100_000);

    expect(app(AddFeeToBills::class)->handle($this->asrama, $this->semester, $this->cashier))->toBe(3);

    $paid = ($this->bill)($this->paid);
    expect($paid->net_amount)->toBe(1_200_000)
        ->and($paid->status)->toBe(BillStatus::Partial)
        ->and(($this->bill)($this->scholar)->net_amount)->toBe(600_000)
        ->and(($this->bill)($this->other)->net_amount)->toBe(1_300_000)
        ->and($this->paid->user->notifications()->sole()->data['title'])->toContain('Asrama')
        ->and($wali->user->notifications()->count())->toBe(1);

    // Running it again adds nothing twice.
    expect(app(AddFeeToBills::class)->preview($this->asrama, $this->semester)['skipped']['has_fee'])->toBe(3)
        ->and(app(AddFeeToBills::class)->handle($this->asrama, $this->semester, $this->cashier))->toBe(0);
});

test('void bills, one-time fees billed before and fees without a rate are skipped', function () {
    $once = FeeType::factory()->create(['name' => 'Jas almamater', 'frequency' => FeeFrequency::Once]);
    FeeRate::create(['fee_type_id' => $once->id, 'academic_year_id' => $this->semester->academic_year_id, 'class_group_id' => $this->classA->id, 'amount' => 150_000]);

    $preview = app(AddFeeToBills::class)->preview($once, $this->semester);
    expect($preview['entries'])->toHaveCount(2)->and($preview['skipped']['no_rate'])->toBe(1);

    app(AddFeeToBills::class)->handle($once, $this->semester, $this->cashier);
    ($this->bill)($this->scholar)->update(['status' => BillStatus::Void]);

    expect(app(AddFeeToBills::class)->preview($this->asrama, $this->semester)['entries'])->toHaveCount(2);
});

test('finance adds the fee from the Fees page', function () {
    $this->actingAs($this->cashier)->get(route('finance.fees'))->assertOk()->assertSee(__('Add to existing bills'));

    Livewire::test('pages::finance.fees')
        ->call('openAddFee')
        ->set('addFeeTypeId', $this->asrama->id)
        ->set('addSemesterId', $this->semester->id)
        ->assertSee(($this->bill)($this->other)->number)
        ->call('addFee')
        ->assertHasNoErrors();

    expect(($this->bill)($this->other)->lines()->where('fee_type_id', $this->asrama->id)->exists())->toBeTrue();
});
