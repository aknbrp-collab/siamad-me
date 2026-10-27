<?php

namespace App\Enums;

enum CashAccountType: string
{
    case Cash = 'cash';
    case Bank = 'bank';

    public function label(): string
    {
        return match ($this) {
            self::Cash => __('Cash (kas)'),
            self::Bank => __('Bank account'),
        };
    }
}
