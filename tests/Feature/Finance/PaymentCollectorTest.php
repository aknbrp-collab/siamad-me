<?php

use App\Actions\Enrollment\ManageClassEnrollments;
use App\Actions\Finance\GenerateBills;
use App\Actions\Finance\RecordPayment;
use App\Actions\Finance\ReviewPayments;
use App\Actions\People\ManageUserAccounts;
use App\Enums\Gender;
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
use App\Models\Student;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $semester = Semester::factory()->active()->create();
    $putraClass = ClassGroup::factory()->create();
    $putriClass = ClassGroup::factory()->putri()->create();

    $this->ahmad = Student::factory()->create();
    $this->fatimah = Student::factory()->female()->create();
    $this->aisyah = Student::factory()->female()->create();

    app(ManageClassEnrollments::class)->enroll($semester, $putraClass, [$this->ahmad->id]);
    app(ManageClassEnrollments::class)->enroll($semester, $putriClass, [$this->fatimah->id, $this->aisyah->id]);

    $fee = FeeType::factory()->create();
    FeeRate::create(['fee_type_id' => $fee->id, 'academic_year_id' => $semester->academic_year_id, 'amount' => 2_000_000]);
    app(GenerateBills::class)->handle($semester);

    $this->putraBill = Bill::query()->where('student_id', $this->ahmad->id)->sole();
    $this->putriBill = Bill::query()->where('student_id', $this->aisyah->id)->sole();

    // Fatimah (a mahasantri) collects the putri payments.
    app(ManageUserAccounts::class)->setCollector($this->fatimah->user, Gender::Female);
    $this->collector = $this->fatimah->user->fresh();

    $this->cashier = User::factory()->withRole(Role::AdminPayment)->create();
    $this->admin = User::factory()->withRole(Role::Admin)->create();
});

function actAsCollector(User $collector): void
{
    test()->actingAs($collector)->withSession(['active_role' => Role::PaymentCollector->value]);
}

test('superadmin makes a mahasantri a payment collector for one gender; never a wali', function () {
    $student = Student::factory()->female()->create();
    $this->actingAs(User::factory()->withRole(Role::Superadmin)->create())
        ->get(route('superadmin.users.edit', $student->user))
        ->assertOk()
        ->assertSeeHtml('data-test="collector-form"');

    Livewire::test('pages::superadmin.users.form', ['user' => $student->user])
        ->set('collectorScope', Gender::Female->value)
        ->call('saveCollector')
        ->assertHasNoErrors();

    expect($student->user->fresh()->roleList())->toBe([Role::Student, Role::PaymentCollector])
        ->and($student->user->fresh()->collectorScope())->toBe(Gender::Female);

    Livewire::test('pages::superadmin.users.form', ['user' => $student->user])
        ->assertSet('collectorScope', 'female')
        ->set('collectorScope', '')
        ->call('saveCollector');

    expect($student->user->fresh()->roleList())->toBe([Role::Student]);

    $guardian = Guardian::factory()->create();
    expect(fn () => app(ManageUserAccounts::class)->setCollector($guardian->user, Gender::Male))
        ->toThrow(ValidationException::class);
});

test('a mahasantri collector picks the role and lands on their own home', function () {
    $this->actingAs($this->collector)->get(route('dashboard'))->assertRedirect(route('role.select'));

    actAsCollector($this->collector);
    $this->get(route('dashboard'))->assertRedirect(route('petugas.dashboard'));
    $this->get(route('petugas.dashboard'))->assertOk()->assertSee(route('finance.bills.index'));
});

test('the collector sees and opens only bills of their gender, and cannot administer them', function () {
    actAsCollector($this->collector);

    $this->get(route('finance.bills.index'))
        ->assertOk()
        ->assertSee($this->putriBill->number)
        ->assertDontSee($this->putraBill->number)
        ->assertSeeHtml('data-test="collector-scope"')
        ->assertDontSee(__('Generate bills'));

    $this->get(route('finance.bills.show', $this->putriBill))->assertOk()->assertDontSee(__('Void bill'));
    $this->get(route('finance.bills.show', $this->putraBill))->assertForbidden();

    Livewire::test('pages::finance.bills.index')->call('openGenerate')->assertForbidden();
    Livewire::test('pages::finance.bills.show', ['bill' => $this->putriBill])
        ->set('voidReason', 'salah')
        ->call('voidBill')
        ->assertForbidden();

    // Pages for the finance office only stay closed.
    $this->get(route('finance.fees'))->assertForbidden();
    $this->get(route('finance.transfers'))->assertForbidden();
});

test('payments the collector records are marked as theirs and need the finance office', function () {
    actAsCollector($this->collector);

    Livewire::test('pages::finance.bills.show', ['bill' => $this->putriBill])
        ->set('amount', 1_000_000)
        ->set('paidOn', now()->toDateString())
        ->call('recordPayment')
        ->assertHasNoErrors();

    $payment = Payment::query()->sole();
    expect($payment->source)->toBe(PaymentSource::Collector)
        ->and($payment->recorded_by)->toBe($this->collector->id);

    // She cannot verify anything, not even someone else's entry.
    $transfer = app(RecordPayment::class)->handle($this->putriBill, $this->cashier, [
        'amount' => 500_000, 'method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => 'BSI 1',
    ]);
    expect(fn () => app(ReviewPayments::class)->verifyTransfer($transfer, $this->collector))->toThrow(ValidationException::class);
});

test('admin keuangan receives the collector\'s cash and verifies their transfers', function () {
    $record = app(RecordPayment::class);
    $cash = $record->handle($this->putriBill, $this->collector, ['amount' => 500_000, 'method' => 'cash', 'paid_on' => now()->toDateString()], PaymentSource::Collector);
    $transfer = $record->handle($this->putriBill, $this->collector, ['amount' => 500_000, 'method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => 'BRI 7'], PaymentSource::Collector);

    $handover = app(ReviewPayments::class)->handOver($this->collector, [$cash->id]);

    // Admin Keuangan is told, and finds both on the verification page.
    expect($this->cashier->notifications()->count())->toBe(1);

    $this->actingAs($this->cashier)->get(route('finance.transfers'))
        ->assertOk()
        ->assertSee($handover->number)
        ->assertSee('BRI 7');

    Livewire::test('pages::finance.transfers')
        ->call('openHandover', $handover->id)
        ->call('completeHandover')
        ->assertHasNoErrors()
        ->call('verify', $transfer->id)
        ->assertHasNoErrors();

    expect($cash->fresh()->status)->toBe(PaymentStatus::Verified)
        ->and($transfer->fresh()->status)->toBe(PaymentStatus::Verified)
        ->and($cash->fresh()->verified_by)->toBe($this->cashier->id);
});

test('cash handed over by admin keuangan still needs an admin', function () {
    $cash = app(RecordPayment::class)->handle($this->putraBill, $this->cashier, ['amount' => 500_000, 'method' => 'cash', 'paid_on' => now()->toDateString()]);
    $handover = app(ReviewPayments::class)->handOver($this->cashier, [$cash->id]);
    $otherCashier = User::factory()->withRole(Role::AdminPayment)->create();

    expect(fn () => app(ReviewPayments::class)->completeHandover($handover, $otherCashier))->toThrow(ValidationException::class);

    app(ReviewPayments::class)->completeHandover($handover, $this->admin);
    expect($cash->fresh()->status)->toBe(PaymentStatus::Verified);
});

test('a collector without a putra/putri scope sees no bills', function () {
    $this->collector->roles()->where('role', Role::PaymentCollector)->update(['scope' => null]);
    actAsCollector($this->collector->fresh());

    $this->get(route('finance.bills.index'))
        ->assertOk()
        ->assertDontSee($this->putriBill->number)
        ->assertDontSee($this->putraBill->number)
        ->assertSee(__('No Putra/Putri scope is set for your account yet. Please contact the Superadmin.'));

    $this->get(route('finance.bills.show', $this->putriBill))->assertForbidden();
});
