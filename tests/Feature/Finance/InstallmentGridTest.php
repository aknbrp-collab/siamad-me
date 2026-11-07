<?php

use App\Actions\Enrollment\ManageClassEnrollments;
use App\Actions\Finance\GenerateBills;
use App\Actions\Finance\RecordPayment;
use App\Actions\Finance\ReviewPayments;
use App\Actions\People\ManageUserAccounts;
use App\Enums\Gender;
use App\Enums\PaymentSource;
use App\Enums\Role;
use App\Models\Bill;
use App\Models\ClassGroup;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Support\InstallmentGrid;
use Livewire\Livewire;

beforeEach(function () {
    // Left open (not closed), so its bills can still be paid in the test.
    $this->previous = Semester::factory()->create();
    $this->semester = Semester::factory()->even()->active()->create(['academic_year_id' => $this->previous->academic_year_id]);
    $this->putra = ClassGroup::factory()->create(['name' => 'Semester II Putra']);
    $this->putri = ClassGroup::factory()->putri()->create(['name' => 'Semester II Putri']);

    $this->ahmad = Student::factory()->create(['nim' => '2026110001']);
    $this->umar = Student::factory()->create(['nim' => '2026110002']);
    $this->aisyah = Student::factory()->female()->create(['nim' => '2026120001']);

    $enroll = app(ManageClassEnrollments::class);
    $fee = FeeType::factory()->create();
    FeeRate::create(['fee_type_id' => $fee->id, 'academic_year_id' => $this->semester->academic_year_id, 'amount' => 2_000_000]);

    foreach ([$this->previous, $this->semester] as $semester) {
        $enroll->enroll($semester, $this->putra, [$this->ahmad->id, $this->umar->id]);
        $enroll->enroll($semester, $this->putri, [$this->aisyah->id]);
        app(GenerateBills::class)->handle($semester);
    }

    $this->cashier = User::factory()->withRole(Role::AdminPayment)->create();
});

function gridBill(Student $student, Semester $semester): Bill
{
    return Bill::query()->where('student_id', $student->id)->where('semester_id', $semester->id)->sole();
}

/**
 * A transfer by the mahasantri, verified by the finance office unless told otherwise.
 */
function gridPay(Bill $bill, int $amount, bool $verify = true): void
{
    $payment = app(RecordPayment::class)->handle($bill->fresh(), $bill->student->user, [
        'amount' => $amount, 'method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => 'TRX'.$bill->id.'-'.$amount,
    ], PaymentSource::Payer);

    if ($verify) {
        app(ReviewPayments::class)->verifyTransfer($payment, test()->cashier);
    }
}

/**
 * @return array<string, array{bill: Bill, class: string, cells: list<string>, arrears: int}>
 */
function gridRows(array $grid): array
{
    return $grid['rows']->mapWithKeys(fn (array $row) => [$row['bill']->student->nim => $row])->all();
}

test('one row per bill, a column per instalment up to the highest limit of the semester', function () {
    $grid = InstallmentGrid::build($this->semester, $this->cashier, Role::AdminPayment);
    expect($grid['columns'])->toBe(3)->and($grid['rows'])->toHaveCount(3);

    // Umar was allowed five instalments: every row now has five cells.
    gridBill($this->umar, $this->semester)->update(['max_installments' => 5]);
    $grid = InstallmentGrid::build($this->semester, $this->cashier, Role::AdminPayment);
    $rows = gridRows($grid);

    expect($grid['columns'])->toBe(5)
        ->and($rows['2026110002']['cells'])->toBe(['open', 'open', 'open', 'open', 'open'])
        ->and($rows['2026110001']['cells'])->toBe(['open', 'open', 'open', 'none', 'none'])
        ->and($rows['2026110001']['class'])->toBe('Semester II Putra');

    // Another semester keeps its own count.
    expect(InstallmentGrid::build($this->previous, $this->cashier, Role::AdminPayment)['columns'])->toBe(3);
});

test('verified payments are ticked, unverified ones wait, a paid bill is done', function () {
    gridPay(gridBill($this->ahmad, $this->semester), 500_000);
    gridPay(gridBill($this->ahmad, $this->semester), 500_000, verify: false);
    gridPay(gridBill($this->umar, $this->semester), 2_000_000);

    $rows = gridRows(InstallmentGrid::build($this->semester, $this->cashier, Role::AdminPayment));

    expect($rows['2026110001']['cells'])->toBe(['paid', 'pending', 'open'])
        ->and($rows['2026110002']['cells'])->toBe(['paid', 'done', 'done'])
        ->and($rows['2026120001']['cells'])->toBe(['open', 'open', 'open']);
});

test('arrears from earlier semesters are summed per mahasantri', function () {
    gridPay(gridBill($this->ahmad, $this->previous), 500_000);
    gridPay(gridBill($this->umar, $this->previous), 2_000_000);
    gridPay(gridBill($this->aisyah, $this->previous), 300_000, verify: false);

    $rows = gridRows(InstallmentGrid::build($this->semester, $this->cashier, Role::AdminPayment));

    expect($rows['2026110001']['arrears'])->toBe(1_500_000)
        ->and($rows['2026110002']['arrears'])->toBe(0)
        // Only verified money counts as paid.
        ->and($rows['2026120001']['arrears'])->toBe(2_000_000);

    // The earliest semester has nothing before it.
    expect(InstallmentGrid::build($this->previous, $this->cashier, Role::AdminPayment)['rows']->sum('arrears'))->toBe(0);
});

test('the grid filters by class, Putra/Putri and search', function () {
    $nims = fn (...$filters) => array_map(strval(...), array_keys(gridRows(InstallmentGrid::build($this->semester, $this->cashier, Role::AdminPayment, ...$filters))));

    expect($nims($this->putri->id))->toBe(['2026120001'])
        ->and($nims(null, '', Gender::Male))->toBe(['2026110001', '2026110002'])
        ->and($nims(null, '2026110002'))->toBe(['2026110002'])
        ->and($nims(null, mb_strtolower($this->aisyah->user->name)))->toBe(['2026120001']);
});

test('the Tagihan page shows the grid with links, export and print', function () {
    gridPay(gridBill($this->ahmad, $this->previous), 500_000);
    gridPay(gridBill($this->ahmad, $this->semester), 500_000);
    $bill = gridBill($this->umar, $this->semester);

    $this->actingAs($this->cashier)->withSession(['active_role' => Role::AdminPayment->value]);

    Livewire::withQueryParams(['view' => 'grid'])->test('pages::finance.bills.index')
        ->assertSeeHtml('data-test="installment-grid"')
        ->assertSeeHtml('data-cell="paid"')
        ->assertSeeHtml(route('finance.bills.show', $bill))
        ->assertSee('1.500.000')
        ->assertSee(__('Print'))
        ->set('classId', $this->putri->id)
        ->assertSee($this->aisyah->user->name)
        ->assertDontSee($this->ahmad->user->name)
        ->call('exportGrid')
        ->assertFileDownloaded('rekap-angsuran-'.$this->semester->id.'.xlsx');

    // "All semesters" is not offered in the grid: switching picks a semester.
    Livewire::withQueryParams(['semester' => 0])->test('pages::finance.bills.index')
        ->set('view', 'grid')
        ->assertSet('semesterId', $this->semester->id);
});

test('a payment collector sees the grid of their own mahasantri only', function () {
    $collector = Student::factory()->female()->create();
    app(ManageUserAccounts::class)->setCollector($collector->user, Gender::Female);
    $user = $collector->user->fresh();

    expect(array_map(strval(...), array_keys(gridRows(InstallmentGrid::build($this->semester, $user, Role::PaymentCollector)))))->toBe(['2026120001']);

    $this->actingAs($user)->withSession(['active_role' => Role::PaymentCollector->value]);
    Livewire::withQueryParams(['view' => 'grid'])->test('pages::finance.bills.index')
        ->assertSee($this->aisyah->user->name)
        ->assertDontSee($this->ahmad->user->name)
        ->assertDontSee(__('Putra & Putri'));
});
