<?php

namespace App\Actions\Finance;

use App\Enums\CashAccountType;
use App\Enums\CashEntryType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\CashAccount;
use App\Models\CashCategory;
use App\Models\CashEntry;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Support\CashBook;
use App\Support\MaintenanceMode;
use App\Support\NumberSequence;
use App\Support\Upload;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Writing the cash book (v3 D8): accounts, categories, other income,
 * expenses, transfers between accounts, voiding, and closing the books up
 * to a month. Nothing is deleted. Entries dated in closed books need
 * maintenance mode.
 */
class ManageCashBook
{
    /**
     * @param  array<string, mixed>  $input  name, type, account_number, opening_balance, opening_date, is_active
     */
    public function saveAccount(?CashAccount $account, array $input): CashAccount
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:100', Rule::unique('cash_accounts', 'name')->ignore($account?->id)],
            'type' => ['required', Rule::enum(CashAccountType::class)],
            'account_number' => ['nullable', 'string', 'max:50'],
            'opening_balance' => ['required', 'integer', 'min:0'],
            'opening_date' => ['required', 'date'],
            'is_active' => ['boolean'],
        ])->validate();

        $openingChanged = $account === null
            || (int) $data['opening_balance'] !== $account->opening_balance
            || ! Carbon::parse($data['opening_date'])->isSameDay($account->opening_date);

        if ($openingChanged) {
            $this->ensureOpen(Carbon::parse($data['opening_date']), 'opening_date');
        }

        return CashAccount::query()->updateOrCreate(['id' => $account?->id], $data);
    }

    public function setDefaults(?int $cashAccountId, ?int $bankAccountId): void
    {
        foreach (['cashbook.default_cash_account' => $cashAccountId, 'cashbook.default_bank_account' => $bankAccountId] as $key => $id) {
            $id = $id !== null && CashAccount::query()->whereKey($id)->exists() ? $id : null;

            if (Setting::get($key) !== $id) {
                Setting::set($key, $id);
            }
        }
    }

    public function saveCategory(?CashCategory $category, string $kind, string $name, bool $active = true): CashCategory
    {
        $data = Validator::make(['kind' => $kind, 'name' => trim($name)], [
            'kind' => ['required', 'in:income,expense'],
            'name' => ['required', 'string', 'max:100', Rule::unique('cash_categories')->where('kind', $kind)->ignore($category?->id)],
        ])->validate();

        return CashCategory::query()->updateOrCreate(['id' => $category?->id], [...$data, 'is_active' => $active]);
    }

    /**
     * Other income or an expense.
     *
     * @param  array<string, mixed>  $input  type, cash_account_id, entry_date, amount, cash_category_id, party, description, proof
     */
    public function record(array $input, User $by): CashEntry
    {
        $kind = (string) ($input['type'] ?? '');

        $data = Validator::make($input, [
            'type' => ['required', 'in:income,expense'],
            'cash_account_id' => ['required', 'integer', Rule::exists('cash_accounts', 'id')->where('is_active', true)],
            'entry_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'integer', 'min:1'],
            'cash_category_id' => ['required', 'integer', Rule::exists('cash_categories', 'id')->where('kind', $kind)->where('is_active', true)],
            'party' => ['nullable', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:500'],
            'proof' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', Upload::max()],
        ])->validate();

        $type = CashEntryType::from($data['type']);
        $date = Carbon::parse($data['entry_date']);
        $this->ensureOpen($date);
        $this->ensureAfterOpening((int) $data['cash_account_id'], $date);

        return DB::transaction(fn () => CashEntry::create([
            'number' => NumberSequence::next($type->prefix(), $date->year),
            'cash_account_id' => $data['cash_account_id'],
            'type' => $type,
            'direction' => $type === CashEntryType::Income ? 'in' : 'out',
            'entry_date' => $date->toDateString(),
            'amount' => $data['amount'],
            'cash_category_id' => $data['cash_category_id'],
            'party' => isset($data['party']) ? trim($data['party']) : null,
            'description' => trim($data['description']),
            'proof_path' => $this->storeProof($data['proof'] ?? null),
            'recorded_by' => $by->id,
        ]));
    }

    /**
     * Move money between two accounts (e.g. depositing Kas into the bank):
     * one entry out of the first, one into the second, one voucher number.
     *
     * @param  array<string, mixed>  $input  from_account_id, to_account_id, entry_date, amount, description
     * @return array{0: CashEntry, 1: CashEntry}
     */
    public function transfer(array $input, User $by): array
    {
        $data = Validator::make($input, [
            'from_account_id' => ['required', 'integer', Rule::exists('cash_accounts', 'id')->where('is_active', true)],
            'to_account_id' => ['required', 'integer', 'different:from_account_id', Rule::exists('cash_accounts', 'id')->where('is_active', true)],
            'entry_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'integer', 'min:1'],
            'description' => ['required', 'string', 'max:500'],
        ], [
            'to_account_id.different' => __('Choose two different accounts.'),
        ])->validate();

        $date = Carbon::parse($data['entry_date']);
        $this->ensureOpen($date);
        $this->ensureAfterOpening((int) $data['from_account_id'], $date);
        $this->ensureAfterOpening((int) $data['to_account_id'], $date);

        return DB::transaction(function () use ($data, $date, $by) {
            $shared = [
                'number' => NumberSequence::next(CashEntryType::Transfer->prefix(), $date->year),
                'type' => CashEntryType::Transfer,
                'entry_date' => $date->toDateString(),
                'amount' => $data['amount'],
                'description' => trim($data['description']),
                'transfer_id' => (string) Str::uuid(),
                'recorded_by' => $by->id,
            ];

            return [
                CashEntry::create([...$shared, 'cash_account_id' => $data['from_account_id'], 'direction' => 'out']),
                CashEntry::create([...$shared, 'cash_account_id' => $data['to_account_id'], 'direction' => 'in']),
            ];
        });
    }

    /**
     * A wrong entry stays in the book, marked void. Both halves of a transfer
     * are voided together.
     */
    public function void(CashEntry $entry, string $reason, User $by): void
    {
        if ($entry->isVoid()) {
            throw ValidationException::withMessages(['voidReason' => __('This entry is already void.')]);
        }

        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['voidReason' => __('Give the reason for voiding (at least 5 characters).')]);
        }

        $this->ensureOpen($entry->entry_date, 'voidReason');

        DB::transaction(function () use ($entry, $reason, $by) {
            $entries = $entry->transfer_id !== null
                ? CashEntry::query()->where('transfer_id', $entry->transfer_id)->get()
                : collect([$entry]);

            foreach ($entries as $item) {
                $item->update(['voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => trim($reason)]);
            }
        });
    }

    /**
     * Close the books up to the end of a month: entries on or before it can
     * then only change in maintenance mode. Closing never moves backwards.
     */
    public function closeThrough(string $month): Carbon
    {
        $end = Carbon::parse($month.'-01')->endOfMonth()->startOfDay();
        $current = CashBook::closedThrough();

        if ($end->isFuture()) {
            throw ValidationException::withMessages(['closeMonth' => __('Only a month that has ended can be closed.')]);
        }

        if ($current !== null && $end->lte($current)) {
            throw ValidationException::withMessages(['closeMonth' => __('The books are already closed through :date.', ['date' => $current->translatedFormat('F Y')])]);
        }

        Setting::set('cashbook.closed_through', $end->toDateString());

        return $end;
    }

    /**
     * Book verified tuition payments that have no account yet (paid before
     * the cash book existed) to an account, by payment method.
     */
    public function assignUnbooked(PaymentMethod $method, CashAccount $account): int
    {
        return Payment::query()
            ->whereNull('cash_account_id')
            ->where('status', PaymentStatus::Verified)
            ->where('method', $method)
            ->get()
            ->each(fn (Payment $payment) => $payment->update(['cash_account_id' => $account->id]))
            ->count();
    }

    private function ensureOpen(CarbonInterface $date, string $field = 'entry_date'): void
    {
        $closed = CashBook::closedThrough();

        if ($closed !== null && $date->lte($closed) && ! MaintenanceMode::allowsEditing()) {
            throw ValidationException::withMessages([$field => __('The books are closed through :date. Entries on or before it need maintenance mode.', [
                'date' => $closed->translatedFormat('j F Y'),
            ])]);
        }
    }

    private function ensureAfterOpening(int $accountId, CarbonInterface $date): void
    {
        $account = CashAccount::query()->whereKey($accountId)->firstOrFail();

        if ($date->lt($account->opening_date)) {
            throw ValidationException::withMessages(['entry_date' => __(':account starts on :date (its opening balance). Earlier money is part of that balance.', [
                'account' => $account->name,
                'date' => $account->opening_date->translatedFormat('j F Y'),
            ])]);
        }
    }

    private function storeProof(mixed $proof): ?string
    {
        if (! $proof instanceof UploadedFile) {
            return null;
        }

        $path = $proof->store('cashbook/'.now()->format('Y/m'), 'local');

        if ($path === false) {
            throw new RuntimeException('The proof could not be stored.');
        }

        return $path;
    }
}
