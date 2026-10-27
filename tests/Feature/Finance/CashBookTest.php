<?php

use App\Actions\Enrollment\ManageClassEnrollments;
use App\Actions\Finance\GenerateBills;
use App\Actions\Finance\ManageCashBook;
use App\Actions\Finance\RecordPayment;
use App\Actions\Finance\ReviewPayments;
use App\Enums\BillStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Bill;
use App\Models\CashCategory;
use App\Models\CashEntry;
use App\Models\ClassGroup;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Support\CashBook;
use App\Support\MaintenanceMode;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\SimpleExcel\SimpleExcelWriter;

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(Carbon::parse('2026-10-20 10:00'));

    $this->semester = Semester::factory()->active()->create();
    $classGroup = ClassGroup::factory()->create();
    $spp = FeeType::factory()->create(['name' => 'SPP']);
    FeeRate::create(['fee_type_id' => $spp->id, 'academic_year_id' => $this->semester->academic_year_id, 'amount' => 1_500_000]);
    $this->student = Student::factory()->create(['nim' => '2026110001', 'entry_year' => 2026]);
    app(ManageClassEnrollments::class)->enroll($this->semester, $classGroup, [$this->student->id]);
    app(GenerateBills::class)->handle($this->semester, '2026-09-30');
    $this->bill = Bill::query()->sole();

    $this->cashier = User::factory()->withRole(Role::AdminPayment)->create();
    $this->admin = User::factory()->withRole(Role::Admin)->create();

    $this->book = app(ManageCashBook::class);
    $this->kas = $this->book->saveAccount(null, ['name' => 'Kas', 'type' => 'cash', 'opening_balance' => 1_000_000, 'opening_date' => '2026-09-01', 'is_active' => true]);
    $this->bsi = $this->book->saveAccount(null, ['name' => 'BSI', 'type' => 'bank', 'account_number' => '7123456789', 'opening_balance' => 5_000_000, 'opening_date' => '2026-09-01', 'is_active' => true]);
    $this->honor = CashCategory::query()->where('kind', 'expense')->where('name', 'Honorarium')->sole();
    $this->infaq = CashCategory::query()->where('kind', 'income')->where('name', 'Infaq dan donasi')->sole();
});

function expense(object $test, array $overrides = []): CashEntry
{
    return $test->book->record([
        'type' => 'expense', 'cash_account_id' => $test->kas->id, 'entry_date' => '2026-10-05', 'amount' => 300_000,
        'cash_category_id' => $test->honor->id, 'party' => 'Ust. Ahmad', 'description' => 'Honor mengajar', ...$overrides,
    ], $test->cashier);
}

/**
 * @param  list<array<string, string>>  $rows
 */
function cashSheet(array $rows): File
{
    $path = tempnam(sys_get_temp_dir(), 'cash').'.xlsx';
    $writer = SimpleExcelWriter::create($path);
    $writer->addRows($rows);
    $writer->close();

    return UploadedFile::fake()->createWithContent('cash.xlsx', file_get_contents($path));
}

test('starter categories exist and a balance is opening + tuition + income − expenses ± transfers', function () {
    expect(CashCategory::query()->where('kind', 'expense')->count())->toBe(7)
        ->and(CashCategory::query()->where('kind', 'income')->count())->toBe(3);

    // Tuition paid in cash, recorded into Kas and verified through a handover.
    $payment = app(RecordPayment::class)->handle($this->bill, $this->cashier, ['amount' => 500_000, 'method' => 'cash', 'paid_on' => '2026-10-02', 'cash_account_id' => $this->kas->id]);
    $handover = app(ReviewPayments::class)->handOver($this->cashier, [$payment->id]);
    app(ReviewPayments::class)->completeHandover($handover, $this->admin, [], $this->bsi->id);
    expect($payment->fresh()->cash_account_id)->toBe($this->kas->id); // the recorder's choice is kept

    $this->book->record(['type' => 'income', 'cash_account_id' => $this->kas->id, 'entry_date' => '2026-10-03', 'amount' => 200_000, 'cash_category_id' => $this->infaq->id, 'description' => 'Infaq Jumat'], $this->cashier);
    expense($this);
    [$out, $in] = $this->book->transfer(['from_account_id' => $this->kas->id, 'to_account_id' => $this->bsi->id, 'entry_date' => '2026-10-06', 'amount' => 400_000, 'description' => 'Setor ke bank'], $this->cashier);
    expense($this, ['amount' => 999_999, 'description' => 'Salah input'])->update(['voided_at' => now(), 'voided_by' => $this->cashier->id, 'void_reason' => 'x']);

    expect(CashBook::balance($this->kas))->toBe(1_000_000 + 500_000 + 200_000 - 300_000 - 400_000)
        ->and(CashBook::balance($this->bsi))->toBe(5_400_000)
        ->and($out->number)->toBe($in->number)->toStartWith('MUT/2026/')
        ->and(CashBook::balance($this->kas, Carbon::parse('2026-10-03')))->toBe(1_500_000);

    $ledger = CashBook::ledger($this->kas, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
    expect($ledger['start'])->toBe(1_000_000)
        ->and(array_column($ledger['rows'], 'balance'))->toBe([1_500_000, 1_700_000, 1_400_000, 1_000_000])
        ->and($ledger['end'])->toBe(1_000_000);

    $summary = CashBook::summary(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
    expect($summary['tuition'])->toBe(500_000)
        ->and($summary['income']->all())->toBe(['Infaq dan donasi' => 200_000])
        ->and($summary['expense']->all())->toBe(['Honorarium' => 300_000])
        ->and($summary['total_in'] - $summary['total_out'])->toBe(400_000);
});

test('verified transfers go into the chosen or the default bank account', function () {
    $this->book->setDefaults($this->kas->id, $this->bsi->id);
    $student = $this->student->user;

    $first = Payment::create(['bill_id' => $this->bill->id, 'amount' => 300_000, 'method' => PaymentMethod::Transfer, 'source' => PaymentSource::Payer, 'paid_on' => '2026-10-01', 'reference' => 'TRX1', 'status' => PaymentStatus::Unverified, 'recorded_by' => $student->id]);
    app(ReviewPayments::class)->verifyTransfer($first, $this->cashier);
    expect($first->fresh()->cash_account_id)->toBe($this->bsi->id);

    $kas2 = $this->book->saveAccount(null, ['name' => 'BRI', 'type' => 'bank', 'opening_balance' => 0, 'opening_date' => '2026-09-01', 'is_active' => true]);
    $second = Payment::create(['bill_id' => $this->bill->id, 'amount' => 300_000, 'method' => PaymentMethod::Transfer, 'source' => PaymentSource::Payer, 'paid_on' => '2026-10-02', 'reference' => 'TRX2', 'status' => PaymentStatus::Unverified, 'recorded_by' => $student->id]);
    app(ReviewPayments::class)->verifyTransfer($second, $this->cashier, $kas2->id);
    expect($second->fresh()->cash_account_id)->toBe($kas2->id);
});

test('expenses are validated, numbered and keep their proof', function () {
    $entry = expense($this, ['proof' => UploadedFile::fake()->create('kuitansi.pdf', 50, 'application/pdf')]);

    expect($entry->number)->toBe('BKK/2026/000001')
        ->and($entry->direction)->toBe('out')
        ->and(Storage::disk('local')->exists($entry->proof_path))->toBeTrue();

    expect(fn () => expense($this, ['cash_category_id' => $this->infaq->id]))->toThrow(ValidationException::class);
    expect(fn () => expense($this, ['entry_date' => '2026-10-21']))->toThrow(ValidationException::class);
    expect(fn () => expense($this, ['entry_date' => '2026-08-31']))->toThrow(ValidationException::class, 'opening balance');

    $this->kas->update(['is_active' => false]);
    expect(fn () => expense($this))->toThrow(ValidationException::class);
});

test('voiding needs a reason and voids both halves of a transfer', function () {
    [$out, $in] = $this->book->transfer(['from_account_id' => $this->kas->id, 'to_account_id' => $this->bsi->id, 'entry_date' => '2026-10-06', 'amount' => 400_000, 'description' => 'Setor ke bank'], $this->cashier);

    expect(fn () => $this->book->void($in, '', $this->cashier))->toThrow(ValidationException::class);

    $this->book->void($in, 'Salah rekening tujuan', $this->cashier);

    expect($out->fresh()->isVoid())->toBeTrue()
        ->and($in->fresh()->void_reason)->toBe('Salah rekening tujuan')
        ->and(CashBook::balance($this->kas))->toBe(1_000_000)
        ->and(fn () => $this->book->void($in->fresh(), 'Lagi lagi', $this->cashier))->toThrow(ValidationException::class, 'already void');
});

test('closed months need maintenance mode', function () {
    $entry = expense($this, ['entry_date' => '2026-09-15']);

    expect(fn () => $this->book->closeThrough('2026-10'))->toThrow(ValidationException::class, 'has ended');
    $this->book->closeThrough('2026-09');
    expect(fn () => $this->book->closeThrough('2026-08'))->toThrow(ValidationException::class, 'already closed');

    $this->actingAs($this->cashier);
    session(['active_role' => Role::AdminPayment->value]);

    expect(fn () => expense($this, ['entry_date' => '2026-09-30']))->toThrow(ValidationException::class, 'closed through')
        ->and(fn () => $this->book->void($entry, 'Salah tanggal', $this->cashier))->toThrow(ValidationException::class, 'closed through');
    expense($this, ['entry_date' => '2026-10-01']);

    MaintenanceMode::turnOn(User::factory()->withRole(Role::Superadmin)->create(), 'Koreksi buku September');
    expense($this, ['entry_date' => '2026-09-30']);
    $this->book->void($entry, 'Salah tanggal', $this->cashier);

    expect(CashEntry::query()->counting()->count())->toBe(2);
});

test('tuition paid before the cash book is booked to an account afterwards', function () {
    $payment = app(RecordPayment::class)->handle($this->bill, $this->cashier, ['amount' => 500_000, 'method' => 'cash', 'paid_on' => '2026-10-02']);
    app(ReviewPayments::class)->completeHandover(app(ReviewPayments::class)->handOver($this->cashier, [$payment->id]), $this->admin);

    expect($payment->fresh()->cash_account_id)->toBeNull()
        ->and(CashBook::summary(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'))['unassigned'])->toBe(500_000);

    expect($this->book->assignUnbooked(PaymentMethod::Cash, $this->kas))->toBe(1)
        ->and(CashBook::balance($this->kas))->toBe(1_500_000);
});

test('the cash book pages record, transfer, void and print', function () {
    $this->actingAs($this->cashier)->get(route('finance.cashbook'))->assertOk()->assertSee('Kas');
    $this->get(route('finance.cash-accounts'))->assertOk()->assertSee('7123456789');

    Livewire::test('pages::finance.cash-accounts')
        ->call('createAccount')
        ->set('name', 'Kas Kecil')->set('type', 'cash')->set('openingBalance', 250_000)->set('openingDate', '2026-10-01')
        ->call('saveAccount')
        ->assertHasNoErrors()
        ->set('defaultCash', $this->kas->id)->set('defaultBank', $this->bsi->id)
        ->call('saveDefaults');
    expect(CashBook::defaultAccountId(PaymentMethod::Transfer))->toBe($this->bsi->id);

    $page = Livewire::test('pages::finance.cashbook')
        ->set('month', '2026-10')
        ->call('newEntry', 'expense')
        ->set('entryAmount', 150_000)
        ->set('entryCategoryId', $this->honor->id)
        ->set('entryDescription', 'Honor imam tarawih')
        ->call('saveEntry')
        ->assertHasNoErrors()
        ->assertSee('Honor imam tarawih')
        ->call('newTransfer')
        ->set('toAccountId', $this->bsi->id)
        ->set('transferAmount', 100_000)
        ->set('transferDescription', 'Setor')
        ->call('saveTransfer')
        ->assertHasNoErrors();

    $entry = CashEntry::query()->where('type', 'expense')->sole();
    $page->call('startVoid', $entry->id)->set('voidReason', 'Dobel input')->call('voidEntry')->assertHasNoErrors();
    expect($entry->fresh()->isVoid())->toBeTrue();

    $page->call('exportLedger')->assertFileDownloaded();
    $page->set('tab', 'report')->assertSee(__('Surplus / deficit'));

    $this->withSession(['active_role' => Role::AdminPayment->value])
        ->get(route('finance.cash-report', ['from' => '2026-09', 'to' => '2026-10']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    $this->get(route('finance.dashboard'))->assertOk()->assertSee('data-test="account-balances"', false);
});

test('expense proofs are for finance staff only', function () {
    $entry = expense($this, ['proof' => UploadedFile::fake()->create('kuitansi.pdf', 50, 'application/pdf')]);

    $this->actingAs($this->cashier)->withSession(['active_role' => Role::AdminPayment->value])
        ->get(route('files.show', ['cash-proof', $entry->id]))->assertOk();
    $this->actingAs($this->student->user)->withSession(['active_role' => Role::Student->value])
        ->get(route('files.show', ['cash-proof', $entry->id]))->assertForbidden();
});

test('previous-year bills, payments, income and expenses are imported in maintenance mode', function () {
    MaintenanceMode::turnOn(User::factory()->withRole(Role::Superadmin)->create(), 'Input keuangan 2025/2026');
    $past = Semester::factory()->closed()->create();
    $this->kas->update(['opening_date' => '2025-07-01']);
    $other = Student::factory()->create(['nim' => '2025110002']);

    $this->actingAs($this->cashier)->get(route('finance.history'))->assertOk()->assertSee(__('Bills & payments'))->assertDontSee(__('Final grades'));

    $bills = Livewire::test('pages::admin.history')
        ->set('type', 'bills')
        ->set('semesterId', $past->id)
        ->set('file', cashSheet([
            ['nim*' => '2026110001', 'billed*' => '3.500.000', 'paid' => '3500000', 'paid_on' => '2025-09-15', 'method' => 'tunai', 'account' => 'kas', 'description' => 'SPP dan asrama'],
            ['nim*' => '2025110002', 'billed*' => '3500000', 'paid' => '1000000', 'paid_on' => '2025-09-20', 'method' => 'transfer', 'account' => '', 'description' => ''],
            ['nim*' => '2025110003', 'billed*' => '3500000', 'paid' => '4000000', 'paid_on' => '', 'method' => 'cek', 'account' => 'Dana', 'description' => ''],
        ]))
        ->call('check')
        ->assertSet('validCount', 2);
    expect(collect($bills->get('rowErrors'))->pluck('row')->all())->toBe([4]);
    $bills->call('import')->assertSet('importedCount', 2);

    $paid = Bill::query()->where('semester_id', $past->id)->where('student_id', $this->student->id)->sole();
    expect($paid->status)->toBe(BillStatus::Paid)
        ->and($paid->lines()->sole()->description)->toBe('SPP dan asrama')
        ->and($paid->payments()->sole())
        ->source->toBe(PaymentSource::Import)
        ->status->toBe(PaymentStatus::Verified)
        ->cash_account_id->toBe($this->kas->id);
    expect(Bill::query()->where('semester_id', $past->id)->where('student_id', $other->id)->sole()->status)->toBe(BillStatus::Partial);

    $cash = Livewire::test('pages::admin.history')
        ->set('type', 'cash')
        ->set('file', cashSheet([
            ['date*' => '2025-09-30', 'type*' => 'keluar', 'category*' => 'honorarium', 'amount*' => '1500000', 'account*' => 'Kas', 'party' => 'Ust. Ahmad', 'description*' => 'Honor September'],
            ['date*' => '2025-10-01', 'type*' => 'masuk', 'category*' => 'Infaq dan donasi', 'amount*' => '250.000', 'account*' => 'Kas', 'party' => '', 'description*' => 'Infaq'],
            ['date*' => '2025-06-30', 'type*' => 'keluar', 'category*' => 'Listrik', 'amount*' => 'x', 'account*' => 'Kas', 'party' => '', 'description*' => ''],
        ]))
        ->call('check')
        ->assertSet('validCount', 2);
    $cash->call('import')->assertSet('importedCount', 2);

    expect(CashEntry::query()->orderBy('entry_date')->pluck('number')->all())->toBe(['BKK/2025/000001', 'BKM/2025/000001'])
        ->and(CashBook::balance($this->kas, Carbon::parse('2025-10-02')))->toBe(1_000_000 + 3_500_000 - 1_500_000 + 250_000);
});

test('admin keuangan sees only the finance imports, admin only the academic ones', function () {
    MaintenanceMode::turnOn(User::factory()->withRole(Role::Superadmin)->create(), 'Input data 2025/2026');

    $this->actingAs($this->admin)->get(route('admin.history'))->assertOk()->assertSee(__('Final grades'))->assertDontSee(__('Bills & payments'));
    Livewire::test('pages::admin.history')->set('type', 'cash')->assertForbidden();
});
