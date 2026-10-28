<?php

use App\Actions\Enrollment\ManageClassEnrollments;
use App\Actions\Finance\GenerateBills;
use App\Actions\Finance\RecordPayment;
use App\Actions\Finance\ReviewPayments;
use App\Enums\PaymentSource;
use App\Enums\Role;
use App\Models\Bill;
use App\Models\ClassGroup;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Support\PendingPayments;
use Livewire\Livewire;

beforeEach(function () {
    $semester = Semester::factory()->active()->create();
    $class = ClassGroup::factory()->create();
    $students = Student::factory()->count(3)->create();
    app(ManageClassEnrollments::class)->enroll($semester, $class, $students->modelKeys());

    $fee = FeeType::factory()->create();
    FeeRate::create(['fee_type_id' => $fee->id, 'academic_year_id' => $semester->academic_year_id, 'amount' => 2_000_000]);
    app(GenerateBills::class)->handle($semester);
    $this->bills = Bill::query()->with('student.user')->orderBy('id')->get();

    $this->cashier = User::factory()->withRole(Role::AdminPayment)->create(['name' => 'Bu Keuangan']);
    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $record = app(RecordPayment::class);

    $this->travel = fn (int $days) => $this->travelTo(now()->subDays($days));

    // 10 days ago: cash still in the cashier's hands.
    $this->travelTo(now()->subDays(10));
    $this->held = $record->handle($this->bills[0], $this->cashier, ['amount' => 500_000, 'method' => 'cash', 'paid_on' => now()->toDateString()]);
    $this->travelBack();

    // 4 days ago: cash handed over, not counted by an Admin yet.
    $this->travelTo(now()->subDays(4));
    $this->handedOver = $record->handle($this->bills[1], $this->cashier, ['amount' => 300_000, 'method' => 'cash', 'paid_on' => now()->toDateString()]);
    $this->handover = app(ReviewPayments::class)->handOver($this->cashier, [$this->handedOver->id]);
    $this->travelBack();

    // Today: a transfer the mahasantri confirmed.
    $this->transfer = $record->handle($this->bills[2], $this->bills[2]->student->user, ['amount' => 1_000_000, 'method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => 'TRX1'], PaymentSource::Payer);
});

test('each unverified payment shows its stage, who it came from and whom it waits with', function () {
    $rows = PendingPayments::rows()->keyBy(fn (array $row) => $row['payment']->id);

    expect($rows)->toHaveCount(3)
        ->and($rows[$this->held->id]['stage'])->toBe(PendingPayments::CASH_HELD)
        ->and($rows[$this->held->id]['holder'])->toBe('Bu Keuangan')
        ->and($rows[$this->held->id]['days'])->toBe(10)
        ->and($rows[$this->handedOver->id]['stage'])->toBe(PendingPayments::HANDOVER_WAITING)
        ->and($rows[$this->handedOver->id]['holder'])->toBe(__('Admin'))
        ->and($rows[$this->handedOver->id]['days'])->toBe(4)
        ->and($rows[$this->transfer->id]['stage'])->toBe(PendingPayments::TRANSFER_WAITING)
        ->and($rows[$this->transfer->id]['from']->is($this->bills[2]->student->user))->toBeTrue()
        ->and($rows[$this->transfer->id]['holder'])->toBe(__('Admin or Admin Keuangan'));

    // Oldest first; verified payments leave the list.
    expect(PendingPayments::rows()->first()['payment']->is($this->held))->toBeTrue();
    app(ReviewPayments::class)->completeHandover($this->handover, $this->admin);
    expect(PendingPayments::rows())->toHaveCount(2);
});

test('reminders go to whoever holds the payments, once a day', function () {
    $summary = PendingPayments::summary(PendingPayments::rows())->keyBy('stage');
    $by = User::factory()->withRole(Role::Superadmin)->create();

    expect(PendingPayments::remind($summary[PendingPayments::CASH_HELD]['key'], $by))->toBe(1)
        ->and($this->cashier->notifications()->sole()->data['title'])->toContain('hand over 1 cash')
        ->and(PendingPayments::remind($summary[PendingPayments::CASH_HELD]['key'], $by))->toBe(0);

    // Finance-office cash waits for an Admin only, not for Admin Keuangan.
    PendingPayments::remind($summary[PendingPayments::HANDOVER_WAITING]['key'], $by);
    expect($this->admin->notifications()->sole()->data['url'])->toContain('/admin/payments')
        ->and($this->cashier->notifications()->count())->toBe(1);
});

test('finance and admins see the tracker; others do not', function () {
    $this->actingAs($this->cashier)->get(route('finance.pending'))->assertOk()
        ->assertSee('Bu Keuangan')->assertSee(__('Cash not handed over'))->assertSee(__('Transfer not checked'));

    Livewire::test('pages::finance.pending')
        ->set('stage', PendingPayments::TRANSFER_WAITING)
        ->assertSee($this->bills[2]->number)
        ->assertDontSee($this->bills[0]->number)
        ->call('remind', PendingPayments::summary(PendingPayments::rows())->firstWhere('stage', PendingPayments::HANDOVER_WAITING)['key'])
        ->assertHasNoErrors();
    expect($this->admin->notifications()->count())->toBe(1);

    $this->actingAs($this->admin)->get(route('finance.pending'))->assertOk()->assertDontSee(route('finance.bills.show', $this->bills[0]));
    $this->actingAs(User::factory()->withRole(Role::Teacher)->create())->get(route('finance.pending'))->assertForbidden();
});
