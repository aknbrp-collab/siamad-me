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
use App\Models\BillLine;
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
    $yearId = $this->semester->academic_year_id;
    $this->ukt = FeeType::factory()->create(['name' => 'UKT', 'max_installments' => 3]);
    $this->syahriah = FeeType::factory()->monthly()->create(['name' => 'Syahriah', 'months_per_semester' => 5, 'max_installments' => 5]);
    FeeRate::create(['fee_type_id' => $this->ukt->id, 'academic_year_id' => $yearId, 'amount' => 2_000_000]);
    FeeRate::create(['fee_type_id' => $this->syahriah->id, 'academic_year_id' => $yearId, 'amount' => 200_000]);

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

function gridLine(Bill $bill, FeeType $fee): BillLine
{
    return $bill->lines()->where('fee_type_id', $fee->id)->sole();
}

/**
 * A transfer by the mahasantri (spread over the fees in the bill's order, or the fees given), verified by the
 * finance office unless told otherwise.
 *
 * @param  array<int, int>  $allocations
 */
function gridPay(Bill $bill, int $amount, bool $verify = true, array $allocations = []): void
{
    $payment = app(RecordPayment::class)->handle($bill->fresh(), $bill->student->user, [
        'amount' => $amount, 'method' => 'transfer', 'paid_on' => now()->toDateString(), 'reference' => 'TRX'.$bill->id.'-'.$amount.'-'.count($allocations),
        ...($allocations === [] ? [] : ['allocations' => $allocations]),
    ], PaymentSource::Payer);

    if ($verify) {
        app(ReviewPayments::class)->verifyTransfer($payment, test()->cashier);
    }
}

/**
 * @return array<string, array<string, mixed>>
 */
function gridRows(array $grid): array
{
    return $grid['rows']->mapWithKeys(fn (array $row) => [$row['bill']->student->nim => $row])->all();
}

test('all fees: each fee type as paid / left, and the total left', function () {
    $bill = gridBill($this->ahmad, $this->semester);
    gridPay($bill, 2_200_000);
    gridPay($bill, 200_000, verify: false, allocations: [gridLine($bill, $this->syahriah)->id => 200_000]);

    $grid = InstallmentGrid::build($this->semester, $this->cashier, Role::AdminPayment);
    $rows = gridRows($grid);

    expect($grid['mode'])->toBe(InstallmentGrid::ALL)
        ->and(array_column($grid['columns'], 'label'))->toBe(['UKT', 'Syahriah'])
        ->and($grid['rows'])->toHaveCount(3)
        ->and($rows['2026110001']['fees'][$this->ukt->id])->toBe(['due' => 2_000_000, 'paid' => 2_000_000, 'pending' => 0, 'left' => 0])
        // A waiting payment is still left, with a clock.
        ->and($rows['2026110001']['fees'][$this->syahriah->id])->toBe(['due' => 1_000_000, 'paid' => 200_000, 'pending' => 200_000, 'left' => 800_000])
        ->and($rows['2026110001']['left'])->toBe(800_000)
        ->and($rows['2026110002']['left'])->toBe(3_000_000)
        ->and($rows['2026110001']['class'])->toBe('Semester II Putra')
        ->and(InstallmentGrid::feeTypes($this->semester)->pluck('name')->all())->toBe(['Syahriah', 'UKT']);
});

test('a monthly fee: a tick per month of the bill, waiting months with a clock', function () {
    $bill = gridBill($this->ahmad, $this->semester);
    $line = gridLine($bill, $this->syahriah);
    gridPay($bill, 400_000, allocations: [$line->id => 400_000]);
    gridPay($bill, 200_000, verify: false, allocations: [$line->id => 200_000]);

    // Umar's bill was made for 3 months only.
    gridLine(gridBill($this->umar, $this->semester), $this->syahriah)->update(['months' => 3, 'amount' => 600_000]);

    $grid = InstallmentGrid::build($this->semester, $this->cashier, Role::AdminPayment, fee: $this->syahriah);
    $rows = gridRows($grid);

    expect($grid['mode'])->toBe(InstallmentGrid::MONTHLY)
        ->and(array_column($grid['columns'], 'label'))->toBe(['1', '2', '3', '4', '5'])
        ->and($rows['2026110001']['cells'])->toBe(['paid', 'paid', 'pending', 'open', 'open'])
        ->and($rows['2026110001'])->paid->toBe(400_000)->pending->toBe(200_000)->left->toBe(600_000)
        ->and($rows['2026110002']['cells'])->toBe(['open', 'open', 'open', 'none', 'none']);
});

test('another fee: paid, left and instalments; bills without it are left out', function () {
    $bill = gridBill($this->ahmad, $this->semester);
    gridPay($bill, 1_000_000, allocations: [gridLine($bill, $this->ukt)->id => 1_000_000]);
    gridLine(gridBill($this->aisyah, $this->semester), $this->ukt)->delete();

    $grid = InstallmentGrid::build($this->semester, $this->cashier, Role::AdminPayment, fee: $this->ukt);
    $rows = gridRows($grid);

    expect($grid['mode'])->toBe(InstallmentGrid::FEE)
        ->and($grid['columns'])->toBe([])
        ->and(array_keys($rows))->toBe([2026110001, 2026110002])
        ->and($rows['2026110001'])->paid->toBe(1_000_000)->left->toBe(1_000_000)->used->toBe(1)->max->toBe(3)->cells->toBe([]);
});

test('arrears from earlier semesters are summed per mahasantri', function () {
    gridPay(gridBill($this->ahmad, $this->previous), 500_000);
    gridPay(gridBill($this->umar, $this->previous), 3_000_000);
    gridPay(gridBill($this->aisyah, $this->previous), 300_000, verify: false);

    $rows = gridRows(InstallmentGrid::build($this->semester, $this->cashier, Role::AdminPayment));

    expect($rows['2026110001']['arrears'])->toBe(2_500_000)
        ->and($rows['2026110002']['arrears'])->toBe(0)
        // Only verified money counts as paid.
        ->and($rows['2026120001']['arrears'])->toBe(3_000_000);

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

test('the Tagihan page shows the grid by fee with links, export and print', function () {
    gridPay(gridBill($this->ahmad, $this->previous), 500_000);
    $bill = gridBill($this->ahmad, $this->semester);
    gridPay($bill, 200_000, allocations: [gridLine($bill, $this->syahriah)->id => 200_000]);

    $this->actingAs($this->cashier)->withSession(['active_role' => Role::AdminPayment->value]);

    Livewire::withQueryParams(['view' => 'grid'])->test('pages::finance.bills.index')
        ->assertSeeHtml('data-mode="all"')
        ->assertSee('Rp 2.800.000')
        ->assertSee('2.000.000')
        ->assertSee(__('Print'))
        ->call('exportGrid')
        ->assertFileDownloaded('rekap-angsuran-'.$this->semester->id.'.xlsx');

    Livewire::withQueryParams(['view' => 'grid', 'fee' => $this->syahriah->id])->test('pages::finance.bills.index')
        ->assertSeeHtml('data-mode="monthly"')
        ->assertSeeHtml('data-cell="paid"')
        ->assertSeeHtml(route('finance.bills.show', gridBill($this->umar, $this->semester)))
        ->set('classId', $this->putri->id)
        ->assertSee($this->aisyah->user->name)
        ->assertDontSee($this->ahmad->user->name)
        ->call('exportGrid')
        ->assertFileDownloaded('rekap-angsuran-'.$this->semester->id.'-syahriah.xlsx');

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
