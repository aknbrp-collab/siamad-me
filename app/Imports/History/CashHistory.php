<?php

namespace App\Imports\History;

use App\Actions\Finance\ManageCashBook;
use App\Models\CashAccount;
use App\Models\CashCategory;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Past other income and expenses from the paper cash book, one row per
 * entry, each with its own date. Stored like entries made by hand
 * (voucher numbers of their year), so the reports include them.
 */
class CashHistory implements HistoryImporter
{
    /** @var array<string, CashAccount>|null */
    private ?array $accounts = null;

    /** @var array<string, CashCategory>|null "kind|name" => category */
    private ?array $categories = null;

    public function __construct(private readonly ManageCashBook $book) {}

    public function label(): string
    {
        return __('Income & expenses');
    }

    public function needsSemester(): bool
    {
        return false;
    }

    public function columns(): array
    {
        return [
            'date' => __('Required, YYYY-MM-DD.'),
            'type' => __('Required: masuk (income) or keluar (expense).'),
            'category' => __('Required. Category name as on the Accounts & categories page.'),
            'amount' => __('Required, in rupiah.'),
            'account' => __('Required. Account name as on the Accounts & categories page.'),
            'party' => __('Optional: received from / paid to.'),
            'description' => __('Required.'),
        ];
    }

    public function example(): array
    {
        return [
            'date' => '2025-09-30',
            'type' => 'keluar',
            'category' => CashCategory::query()->where('kind', 'expense')->value('name') ?? 'Honorarium',
            'amount' => '1500000',
            'account' => CashAccount::query()->value('name') ?? 'Kas',
            'party' => 'Ust. Ahmad',
            'description' => 'Honor mengajar September',
        ];
    }

    public function key(array $row): string
    {
        // Two identical entries on one day can be real (two equal honoraria): no duplicate check.
        return '';
    }

    public function check(?Semester $semester, array $row): array
    {
        $errors = [];
        $rawDate = trim($row['date'] ?? '');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate) ? Carbon::parse($rawDate) : null;
        $kind = match (Str::lower(trim($row['type'] ?? ''))) {
            'masuk', 'income', 'pemasukan' => 'income',
            'keluar', 'expense', 'pengeluaran' => 'expense',
            default => null,
        };
        $categoryName = trim($row['category'] ?? '');
        $category = $kind === null ? null : ($this->categories()[$kind.'|'.Str::lower($categoryName)] ?? null);
        $accountName = trim($row['account'] ?? '');
        $account = $this->accounts()[Str::lower($accountName)] ?? null;
        $amount = str_replace(['.', ',', ' ', 'Rp', 'rp'], '', trim($row['amount'] ?? ''));

        if ($date === null || $date->isFuture()) {
            $errors[] = __('Give the date (YYYY-MM-DD, not in the future).');
        }

        if ($kind === null) {
            $errors[] = __('Type must be masuk or keluar.');
        } elseif ($category === null) {
            $errors[] = __('No :kind category named ":category".', ['kind' => $kind === 'income' ? __('income') : __('expense'), 'category' => $categoryName]);
        }

        if (! ctype_digit($amount) || (int) $amount < 1) {
            $errors[] = __('Amount must be a whole amount in rupiah.');
        }

        if ($account === null) {
            $errors[] = __('No account named ":account".', ['account' => $accountName]);
        } elseif ($date !== null && $date->lt($account->opening_date)) {
            $errors[] = __(':account starts on :date (its opening balance). Earlier money is part of that balance.', [
                'account' => $account->name,
                'date' => $account->opening_date->translatedFormat('j F Y'),
            ]);
        }

        if (trim($row['description'] ?? '') === '') {
            $errors[] = __('Description is required.');
        }

        return ['input' => [
            'type' => $kind,
            'cash_account_id' => $account?->id,
            'entry_date' => $date?->toDateString(),
            'amount' => (int) $amount,
            'cash_category_id' => $category?->id,
            'party' => trim($row['party'] ?? '') ?: null,
            'description' => trim($row['description'] ?? ''),
        ], 'errors' => $errors];
    }

    public function import(?Semester $semester, array $input, User $by): void
    {
        $this->book->record($input, $by);
    }

    /**
     * @return array<string, CashAccount>
     */
    private function accounts(): array
    {
        return $this->accounts ??= CashAccount::query()->where('is_active', true)->get()->keyBy(fn (CashAccount $account) => Str::lower($account->name))->all();
    }

    /**
     * @return array<string, CashCategory>
     */
    private function categories(): array
    {
        return $this->categories ??= CashCategory::query()->where('is_active', true)->get()
            ->keyBy(fn (CashCategory $category) => $category->kind.'|'.Str::lower($category->name))->all();
    }
}
