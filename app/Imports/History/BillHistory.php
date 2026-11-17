<?php

namespace App\Imports\History;

use App\Actions\Finance\AdjustBill;
use App\Enums\BillStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use App\Models\Bill;
use App\Models\CashAccount;
use App\Models\Semester;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Support\NumberSequence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * A past semester's bill of each mahasantri and what was paid on it, from the
 * paper archive: one row per mahasantri. The bill gets one line with the
 * total; what was paid becomes one verified payment (source: archive),
 * booked to the account given. A bill of the semester without payments (e.g. made with
 * "Buat tagihan") is voided and replaced (after-review D59); one with payments is
 * reported with its number, not changed.
 */
class BillHistory implements HistoryImporter
{
    /** @var array<string, CashAccount>|null accounts by lower-case name */
    private ?array $accounts = null;

    public function label(): string
    {
        return __('Bills & payments');
    }

    public function needsSemester(): bool
    {
        return true;
    }

    public function columns(): array
    {
        return [
            'nim' => __('Required. The mahasantri must already exist in SIAKAD.'),
            'billed' => __('Required. Total billed for the semester, in rupiah.'),
            'paid' => __('Total paid, in rupiah (0 or empty: nothing paid).'),
            'paid_on' => __('Required when something was paid: the date of the (last) payment, YYYY-MM-DD.'),
            'method' => __('Optional: tunai or transfer (default tunai).'),
            'account' => __('Optional: the account the money went into, by name as on the Accounts page.'),
            'description' => __('Optional, e.g. SPP dan asrama. Default: Tagihan dari arsip.'),
        ];
    }

    public function example(): array
    {
        return [
            'nim' => '2025110001',
            'billed' => '3500000',
            'paid' => '3500000',
            'paid_on' => '2025-09-15',
            'method' => 'tunai',
            'account' => CashAccount::query()->value('name') ?? 'Kas',
            'description' => 'SPP dan asrama',
        ];
    }

    public function key(array $row): string
    {
        return Str::lower(trim($row['nim'] ?? ''));
    }

    public function check(?Semester $semester, array $row): array
    {
        if ($semester === null) {
            throw new LogicException('A semester is required.');
        }

        $errors = [];
        $nim = trim($row['nim'] ?? '');
        $billed = $this->amount($row['billed'] ?? '');
        $paidRaw = trim($row['paid'] ?? '');
        $paid = $paidRaw === '' ? 0 : $this->amount($paidRaw);
        $paidOn = trim($row['paid_on'] ?? '');
        $method = match (Str::lower(trim($row['method'] ?? ''))) {
            '', 'tunai', 'cash' => PaymentMethod::Cash,
            'transfer', 'tf', 'bank' => PaymentMethod::Transfer,
            default => null,
        };
        $accountName = trim($row['account'] ?? '');
        $account = $accountName === '' ? null : ($this->accounts()[Str::lower($accountName)] ?? null);

        $student = $nim === '' ? null : Student::query()->where('nim', $nim)->first();

        if ($student === null) {
            $errors[] = $nim === '' ? __('NIM is required.') : __('No mahasantri with NIM :nim.', ['nim' => $nim]);
        } else {
            $existing = $this->existingBill($student, $semester);

            if ($existing !== null && $existing->payments()->whereIn('status', PaymentStatus::counting())->exists()) {
                $errors[] = __('Already has bill :number for this semester, with payments; it is not replaced.', ['number' => $existing->number]);
            }
        }

        if ($billed === null || $billed < 1) {
            $errors[] = __('Billed must be a whole amount in rupiah.');
        }

        if ($paid === null || ($billed !== null && $paid > $billed)) {
            $errors[] = __('Paid must be a whole amount, at most what was billed.');
        }

        $date = null;

        if ($paid > 0) {
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidOn) ? Carbon::parse($paidOn) : null;

            if ($date === null || $date->isFuture()) {
                $errors[] = __('Give the payment date (YYYY-MM-DD, not in the future).');
            }
        }

        if ($method === null) {
            $errors[] = __('Method must be tunai or transfer.');
        }

        if ($accountName !== '' && $account === null) {
            $errors[] = __('No account named ":account".', ['account' => $accountName]);
        } elseif ($account !== null && $date !== null && $date->lt($account->opening_date)) {
            $errors[] = __(':account starts on :date (its opening balance). Earlier money is part of that balance.', [
                'account' => $account->name,
                'date' => $account->opening_date->translatedFormat('j F Y'),
            ]);
        }

        return ['input' => [
            'student_id' => $student?->id,
            'billed' => $billed,
            'paid' => $paid,
            'paid_on' => $date?->toDateString(),
            'method' => $method?->value,
            'cash_account_id' => $account?->id,
            'description' => trim($row['description'] ?? '') ?: 'Tagihan dari arsip',
        ], 'errors' => $errors];
    }

    /**
     * The mahasantri's live bill of the semester, if any.
     */
    private function existingBill(Student $student, Semester $semester): ?Bill
    {
        return Bill::query()->where('student_id', $student->id)->where('semester_id', $semester->id)->where('status', '!=', BillStatus::Void)->first();
    }

    public function import(?Semester $semester, array $input, User $by): void
    {
        if ($semester === null) {
            throw new LogicException('A semester is required.');
        }

        DB::transaction(function () use ($semester, $input, $by) {
            // A bill without payments (made with "Buat tagihan") gives way to the archive (D59).
            $existing = $this->existingBill(Student::query()->whereKey((int) $input['student_id'])->firstOrFail(), $semester);
            if ($existing !== null) {
                app(AdjustBill::class)->void($existing, __('Replaced by the archive data.'));
            }

            $semester->loadMissing('academicYear');
            $year = (int) substr($semester->academicYear->name, 0, 4);

            $bill = Bill::create([
                'student_id' => $input['student_id'],
                'semester_id' => $semester->id,
                'number' => NumberSequence::next('INV', $year),
                'gross_amount' => $input['billed'],
                'net_amount' => $input['billed'],
                'max_installments' => (int) Setting::get('payment.max_installments', 3),
                'status' => BillStatus::Unpaid,
                'status_note' => __('Entered from the archive.'),
            ]);

            $line = $bill->lines()->create(['description' => $input['description'], 'amount' => $input['billed']]);

            if ($input['paid'] > 0) {
                $payment = $bill->payments()->create([
                    'amount' => $input['paid'],
                    'method' => $input['method'],
                    'source' => PaymentSource::Import,
                    'paid_on' => $input['paid_on'],
                    'status' => PaymentStatus::Verified,
                    'recorded_by' => $by->id,
                    'verified_by' => $by->id,
                    'verified_at' => now(),
                    'status_note' => __('Entered from the archive.'),
                    'cash_account_id' => $input['cash_account_id'],
                ]);

                // The archive bill has one line: the payment goes to it (after-review D55).
                $payment->allocations()->create(['bill_line_id' => $line->id, 'amount' => $input['paid']]);
            }

            $bill->recalculate();
        });
    }

    private function amount(string $value): ?int
    {
        $digits = str_replace(['.', ',', ' ', 'Rp', 'rp'], '', trim($value));

        return ctype_digit($digits) ? (int) $digits : null;
    }

    /**
     * @return array<string, CashAccount>
     */
    private function accounts(): array
    {
        return $this->accounts ??= CashAccount::query()->get()->keyBy(fn (CashAccount $account) => Str::lower($account->name))->all();
    }
}
