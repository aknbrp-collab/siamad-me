<?php

namespace App\Enums;

enum BillStatus: string
{
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => __('Unpaid'),
            self::Partial => __('Partly paid'),
            self::Paid => __('Paid'),
            self::Void => __('Void'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unpaid => 'red',
            self::Partial => 'amber',
            self::Paid => 'green',
            self::Void => 'zinc',
        };
    }
}
