<?php

namespace App\Enums;

/**
 * Cash book entries besides tuition payments (those come from the payments).
 */
enum CashEntryType: string
{
    case Income = 'income';
    case Expense = 'expense';
    case Transfer = 'transfer';

    public function label(): string
    {
        return match ($this) {
            self::Income => __('Other income'),
            self::Expense => __('Expense'),
            self::Transfer => __('Transfer between accounts'),
        };
    }

    /**
     * Voucher number prefix: bukti kas masuk / keluar, mutasi.
     */
    public function prefix(): string
    {
        return match ($this) {
            self::Income => 'BKM',
            self::Expense => 'BKK',
            self::Transfer => 'MUT',
        };
    }
}
